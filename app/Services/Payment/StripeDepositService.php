<?php

namespace App\Services\Payment;

use App\Models\DepositMethod;
use App\Models\DepositRequest;
use App\Models\PaymentGateway;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Automatic card deposits via Stripe Checkout (additive 2026-10-07).
 *
 * Flow: business picks an automatic deposit method -> backend creates a
 * Stripe Checkout session -> customer pays -> Stripe calls our webhook ->
 * the wallet is credited automatically, no admin approval needed.
 */
class StripeDepositService
{
    public function __construct(
        protected WalletLedgerService $ledger = new WalletLedgerService()
    ) {}

    /**
     * Create a Stripe Checkout session for a wallet top-up.
     *
     * @return array{url: string, session_id: string, deposit_uuid: string}
     */
    public function createSession(Wallet $wallet, DepositMethod $method, int $amountCents): array
    {
        $gateway = $method->gateway;

        if (!$gateway || !$gateway->is_active || !$gateway->has_credentials || $gateway->driver !== 'stripe') {
            throw new Exception('This payment method is not configured for automatic payments yet.');
        }

        $creds = $gateway->credentials ?? [];
        $secretKey = $creds['secret_key'] ?? null;
        if (!$secretKey) {
            throw new Exception('Stripe is missing its secret key.');
        }

        // Track the deposit before the customer pays, so the webhook can
        // find it. It stays "pending" until Stripe confirms.
        $deposit = DepositRequest::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'method' => $method->key,
            'amount_cents' => $amountCents,
            'currency' => $wallet->currency,
            'status' => 'pending',
            'reference' => null,
            'note' => 'Stripe checkout (automatic)',
        ]);

        $stripe = new StripeClient($secretKey);

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => config('app.frontend_url', config('app.url')) . '/business/billing?deposit=success',
            'cancel_url' => config('app.frontend_url', config('app.url')) . '/business/billing?deposit=cancelled',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($wallet->currency),
                    'unit_amount' => $amountCents,
                    'product_data' => ['name' => 'eBiz Earn wallet top-up'],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'deposit_uuid' => $deposit->uuid,
                'wallet_id' => (string) $wallet->id,
            ],
        ]);

        $deposit->update(['reference' => $session->id]);

        return [
            'url' => $session->url,
            'session_id' => $session->id,
            'deposit_uuid' => $deposit->uuid,
        ];
    }

    /**
     * Handle an incoming Stripe webhook. Verifies the signature, then on a
     * completed checkout session credits the wallet automatically.
     */
    public function handleWebhook(string $payload, ?string $signature): array
    {
        $gateway = PaymentGateway::where('driver', 'stripe')
            ->where('is_active', true)
            ->first();

        $webhookSecret = $gateway?->credentials['webhook_secret'] ?? null;
        if (!$webhookSecret) {
            Log::warning('Stripe webhook: no webhook secret configured.');
            return ['ok' => false, 'message' => 'Webhook not configured.'];
        }

        try {
            $event = Webhook::constructEvent($payload, $signature ?? '', $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook: bad signature.');
            return ['ok' => false, 'message' => 'Invalid signature.'];
        } catch (Exception $e) {
            Log::warning('Stripe webhook: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Invalid payload.'];
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var Session $session */
            $session = $event->data->object;
            $this->fulfillSession($session);
        }

        return ['ok' => true];
    }

    protected function fulfillSession(Session $session): void
    {
        if (($session->payment_status ?? '') !== 'paid') {
            return;
        }

        $depositUuid = $session->metadata->deposit_uuid ?? null;
        if (!$depositUuid) {
            Log::warning('Stripe webhook: session has no deposit_uuid.', ['session' => $session->id]);
            return;
        }

        $deposit = DepositRequest::where('uuid', $depositUuid)->first();
        if (!$deposit) {
            Log::warning('Stripe webhook: deposit not found.', ['uuid' => $depositUuid]);
            return;
        }

        // Idempotent: a retried webhook must not double-credit.
        if ($deposit->status === 'approved') {
            return;
        }

        $wallet = Wallet::findOrFail($deposit->wallet_id);
        $amountCents = (int) ($session->amount_total ?? $deposit->amount_cents);

        $this->ledger->credit(
            $wallet,
            $amountCents,
            'deposit',
            'Card deposit via Stripe',
            'deposit_request',
            $deposit->id,
            [
                'gateway' => 'stripe',
                'stripe_session_id' => $session->id,
                'stripe_payment_intent' => $session->payment_intent ?? null,
            ],
            'stripe:' . $session->id,
        );

        $deposit->update([
            'status' => 'approved',
            'amount_cents' => $amountCents,
            'reference' => $session->id,
        ]);

        Log::info('Stripe webhook: wallet credited.', [
            'deposit_uuid' => $depositUuid,
            'wallet_id' => $wallet->id,
            'amount_cents' => $amountCents,
        ]);
    }
}

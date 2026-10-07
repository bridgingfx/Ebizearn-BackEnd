<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Models\PaymentLog;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentService
{
    public function testGateway(PaymentGateway $gateway): array
    {
        // Stripe: actually hit the API with the saved secret key.
        if ($gateway->driver === 'stripe' && $gateway->has_credentials) {
            $secretKey = $gateway->credentials['secret_key'] ?? null;
            if ($secretKey) {
                try {
                    $stripe = new \Stripe\StripeClient($secretKey);
                    $balance = $stripe->balance->retrieve();
                    $message = 'Stripe connected. Account balance: ' .
                        strtoupper($balance->available[0]->currency ?? 'USD') . ' ' .
                        number_format(($balance->available[0]->amount ?? 0) / 100, 2);
                    $gateway->update([
                        'status' => 'ok',
                        'last_tested_at' => now(),
                        'last_test_message' => $message,
                    ]);

                    return ['ok' => true, 'message' => $message];
                } catch (\Throwable $e) {
                    $message = 'Stripe rejected the keys: ' . mb_substr($e->getMessage(), 0, 200);
                    $gateway->update([
                        'status' => 'failed',
                        'last_tested_at' => now(),
                        'last_test_message' => $message,
                    ]);

                    return ['ok' => false, 'message' => $message];
                }
            }
        }

        // PayPal: get an OAuth token with client_id + secret.
        if ($gateway->driver === 'paypal' && $gateway->has_credentials) {
            $clientId = $gateway->credentials['client_id'] ?? null;
            $secret = $gateway->credentials['client_secret'] ?? null;
            if ($clientId && $secret) {
                try {
                    $ch = curl_init('https://api-m.paypal.com/v1/oauth2/token');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_USERPWD => $clientId . ':' . $secret,
                        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                        CURLOPT_TIMEOUT => 15,
                    ]);
                    $body = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $json = json_decode((string) $body, true);
                    if ($code === 200 && isset($json['access_token'])) {
                        $message = 'PayPal connected. Credentials are valid (live environment).';
                        $gateway->update(['status' => 'ok', 'last_tested_at' => now(), 'last_test_message' => $message]);
                        return ['ok' => true, 'message' => $message];
                    }
                    $err = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code);
                    $message = 'PayPal rejected the credentials: ' . mb_substr((string) $err, 0, 200);
                    $gateway->update(['status' => 'failed', 'last_tested_at' => now(), 'last_test_message' => $message]);
                    return ['ok' => false, 'message' => $message];
                } catch (\Throwable $e) {
                    $message = 'PayPal test failed: ' . mb_substr($e->getMessage(), 0, 200);
                    $gateway->update(['status' => 'failed', 'last_tested_at' => now(), 'last_test_message' => $message]);
                    return ['ok' => false, 'message' => $message];
                }
            }
        }

        // Wise: hit the profiles endpoint with the API token.
        if ($gateway->driver === 'wise' && $gateway->has_credentials) {
            $token = $gateway->credentials['api_token'] ?? null;
            if ($token) {
                try {
                    $ch = curl_init('https://api.wise.com/v1/profiles');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                        CURLOPT_TIMEOUT => 15,
                    ]);
                    $body = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code === 200) {
                        $profiles = json_decode((string) $body, true);
                        $count = is_array($profiles) ? count($profiles) : 0;
                        $message = "Wise connected. Found {$count} profile(s) on this token.";
                        $gateway->update(['status' => 'ok', 'last_tested_at' => now(), 'last_test_message' => $message]);
                        return ['ok' => true, 'message' => $message];
                    }
                    $message = ($code === 401 || $code === 403)
                        ? 'Wise rejected the API token: invalid or expired token.'
                        : 'Wise test failed: HTTP ' . $code;
                    $gateway->update(['status' => 'failed', 'last_tested_at' => now(), 'last_test_message' => $message]);
                    return ['ok' => false, 'message' => $message];
                } catch (\Throwable $e) {
                    $message = 'Wise test failed: ' . mb_substr($e->getMessage(), 0, 200);
                    $gateway->update(['status' => 'failed', 'last_tested_at' => now(), 'last_test_message' => $message]);
                    return ['ok' => false, 'message' => $message];
                }
            }
        }

        $message = $this->isLogOnly($gateway)
            ? 'Gateway is configured for log mode. Attempts will be recorded without moving money.'
            : 'Credentials are stored. Real provider API wiring is pending launch-provider selection.';

        $gateway->update([
            'status' => 'ok',
            'last_tested_at' => now(),
            'last_test_message' => $message,
        ]);

        return ['ok' => true, 'message' => $message];
    }

    public function payoutWithdrawal(WithdrawalRequest $withdrawal): array
    {
        $gateway = $this->resolveGateway($withdrawal->payout_method);

        if (!$gateway || $this->isLogOnly($gateway)) {
            $ref = 'PAYLOG_' . strtoupper(uniqid());
            $this->log('withdrawal.payout', $gateway, 'payout', $withdrawal->amount_cents, $withdrawal->currency, 'logged', $ref, $withdrawal, [
                'payout_method' => $withdrawal->payout_method,
                'payout_details' => $withdrawal->payout_details_json,
            ], 'No active real gateway matched this payout method; logged for manual processing.');

            return ['ok' => true, 'status' => 'logged', 'provider_transaction_id' => $ref];
        }

        try {
            throw new RuntimeException('Real provider API is not wired until a launch payment provider is selected.');
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500);
            Log::error('Payment payout failed', ['withdrawal_id' => $withdrawal->id, 'gateway' => $gateway->name, 'error' => $message]);
            $this->log('withdrawal.payout', $gateway, 'payout', $withdrawal->amount_cents, $withdrawal->currency, 'failed', null, $withdrawal, null, $message);
            return ['ok' => false, 'status' => 'failed', 'message' => $message];
        }
    }

    private function resolveGateway(string $payoutMethod): ?PaymentGateway
    {
        // USDT payouts are ALWAYS manual: the admin sends USDT from the
        // company wallet and records the tx hash. They must never be routed
        // to a real payment rail — resolve to no gateway so the payout is
        // only ever logged for manual processing.
        if (str_contains($payoutMethod, 'crypto') || str_contains($payoutMethod, 'usdc') || $payoutMethod === 'usdt') {
            return null;
        }

        $driver = match (true) {
            str_contains($payoutMethod, 'paypal') => 'paypal',
            str_contains($payoutMethod, 'wise') => 'wise',
            str_contains($payoutMethod, 'stripe') => 'stripe',
            default => 'bank_transfer',
        };

        return PaymentGateway::where('is_active', true)
            ->whereIn('driver', [$driver, 'log'])
            ->orderByRaw("driver = ? desc", [$driver])
            ->first();
    }

    private function isLogOnly(?PaymentGateway $gateway): bool
    {
        return !$gateway || $gateway->driver === 'log' || !$gateway->has_credentials;
    }

    private function log(
        string $eventKey,
        ?PaymentGateway $gateway,
        string $direction,
        int $amountCents,
        string $currency,
        string $status,
        ?string $providerTransactionId = null,
        ?object $reference = null,
        ?array $metadata = null,
        ?string $message = null
    ): void {
        PaymentLog::create([
            'event_key' => $eventKey,
            'payment_gateway_id' => $gateway?->id,
            'gateway_name' => $gateway?->name,
            'direction' => $direction,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => $status,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->id,
            'provider_transaction_id' => $providerTransactionId,
            'message' => $message,
            'metadata_json' => $metadata,
        ]);
    }
}

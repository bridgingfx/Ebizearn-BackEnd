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
        $driver = match (true) {
            str_contains($payoutMethod, 'paypal') => 'paypal',
            str_contains($payoutMethod, 'wise') => 'wise',
            str_contains($payoutMethod, 'crypto'), str_contains($payoutMethod, 'usdc') => 'crypto',
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

<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Console\Command;

/**
 * Release matured retention holds: approved task rewards sit in pending
 * until the task's retention period elapses, then move to available.
 *
 * Idempotent: each hold is released at most once — a hold that already
 * has a retention_release (or retention_hold_cancel) entry is skipped,
 * and releaseHold itself carries an idempotency key per hold.
 */
class ReleaseRetentionHolds extends Command
{
    protected $signature = 'retention:release';

    protected $description = 'Move matured task-reward retention holds from pending to available.';

    public function handle(WalletLedgerService $ledger): int
    {
        $now = now()->toIso8601String();

        $holds = WalletTransaction::where('type', 'retention_hold')
            ->where('metadata_json->release_at', '<=', $now)
            ->orderBy('id')
            ->get();

        $released = 0;
        $skipped = 0;

        foreach ($holds as $hold) {
            $done = WalletTransaction::where('wallet_id', $hold->wallet_id)
                ->whereIn('type', ['retention_release', 'retention_hold_cancel'])
                ->where('metadata_json->hold_transaction_id', $hold->id)
                ->exists();

            if ($done) {
                $skipped++;
                continue;
            }

            $wallet = Wallet::where('id', $hold->wallet_id)->first();

            if (!$wallet) {
                $skipped++;
                continue;
            }

            $ledger->releaseHold(
                $wallet,
                abs((int) $hold->amount_cents),
                'retention_release',
                "Retention matured — reward released (submission #{$hold->reference_id})",
                $hold->reference_type,
                $hold->reference_id,
                ['hold_transaction_id' => $hold->id],
                "retention-release-{$hold->id}"
            );

            $released++;
        }

        $this->info("Released {$released} retention hold(s); skipped {$skipped}.");

        return Command::SUCCESS;
    }
}

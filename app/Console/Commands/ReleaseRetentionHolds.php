<?php

namespace App\Console\Commands;

use App\Models\TaskSubmission;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Verification\PostVerificationService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Console\Command;
use Throwable;

/**
 * End of the task duration: approved rewards sit in pending until the
 * task's retention period elapses, then:
 *
 * - Instagram post tasks (the API confirmed the post at approval): the post
 *   is checked again — still live → released to available; deleted →
 *   refunded to the business that funded it; can't tell → retried later,
 *   then manual review. Never released without that check.
 * - Every other task: released to available, as before.
 *
 * Idempotent: each hold is released at most once — a hold that already
 * has a retention_release (or retention_hold_cancel) entry is skipped,
 * releaseHold carries an idempotency key per hold, and the submission row
 * is locked while its reward moves.
 */
class ReleaseRetentionHolds extends Command
{
    protected $signature = 'retention:release';

    protected $description = 'End of task duration: re-check Instagram posts, then release (or refund) pending task rewards.';

    public function handle(WalletLedgerService $ledger, PostVerificationService $posts): int
    {
        $now = now()->toIso8601String();

        $holds = WalletTransaction::where('type', 'retention_hold')
            ->where('metadata_json->release_at', '<=', $now)
            ->orderBy('id')
            ->get();

        $released = 0;
        $skipped = 0;
        $checked = [];
        $outcomes = ['verified' => 0, 'refunded' => 0, 'retry' => 0, 'manual_review' => 0];

        foreach ($holds as $hold) {
            $done = WalletTransaction::where('wallet_id', $hold->wallet_id)
                ->whereIn('type', ['retention_release', 'retention_hold_cancel'])
                ->where('metadata_json->hold_transaction_id', $hold->id)
                ->exists();

            if ($done) {
                $skipped++;
                continue;
            }

            $submission = $hold->reference_type === TaskSubmission::class ? TaskSubmission::find($hold->reference_id) : null;

            // Post tasks: the final check decides (once per submission per run).
            if ($submission && $submission->platform_media_id) {
                if (isset($checked[$submission->id])) {
                    continue;
                }
                $checked[$submission->id] = true;

                if ($submission->reward_status === 'reverification_required'
                    || ($submission->final_check_due_at && $submission->final_check_due_at->isFuture())) {
                    $skipped++;
                    continue;
                }

                try {
                    $result = $posts->finalCheck($submission);
                    $outcomes[$result] = ($outcomes[$result] ?? 0) + 1;
                } catch (Throwable $e) {
                    report($e);
                    $skipped++;
                }
                continue;
            }

            $wallet = Wallet::where('id', $hold->wallet_id)->first();

            if (!$wallet) {
                $skipped++;
                continue;
            }

            $release = $ledger->releaseHold(
                $wallet,
                abs((int) $hold->amount_cents),
                'retention_release',
                "Retention matured — reward released (submission #{$hold->reference_id})",
                $hold->reference_type,
                $hold->reference_id,
                ['hold_transaction_id' => $hold->id],
                "retention-release-{$hold->id}"
            );

            if ($submission && $submission->reward_status !== 'released') {
                $submission->forceFill(['reward_status' => 'released', 'release_tx_id' => $release->id, 'final_checked_at' => now()])->saveQuietly();
            }

            $released++;
        }

        $this->info("Released {$released} retention hold(s); skipped {$skipped}. Post checks: "
            . collect($outcomes)->map(fn ($n, $k) => "{$k} {$n}")->implode(', ') . '.');

        return Command::SUCCESS;
    }
}

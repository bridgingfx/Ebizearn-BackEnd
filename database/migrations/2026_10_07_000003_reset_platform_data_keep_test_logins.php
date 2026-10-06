<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-time fresh start: clear ALL platform data except the test login
 * accounts, so the platform starts clean.
 *
 * KEPT
 * - The test logins (KEEP_EMAILS) with their profile, business and permission
 *   overrides. Every superadmin account is kept as well, so the platform can
 *   never be locked out of its own control panel.
 * - All configuration: roles, permissions + role grants, task types, task
 *   categories, Task Library templates, platform / system settings, feature
 *   flags, countries, withdrawal + referral rules, email providers and
 *   templates, payment gateways, deposit methods.
 *
 * CLEARED
 * - Every other user account.
 * - Tasks, assignments, submissions, proof files, AI results, campaigns,
 *   wallet ledger, withdrawals, deposits, referrals + rewards, fraud events,
 *   support tickets, audit / email / payment logs, idempotency keys, demo
 *   requests, email OTPs + campaigns, social channels, login tokens, sessions.
 * - Kept wallets are zeroed: with the ledger cleared, any balance left
 *   behind would be money with no transaction history.
 *
 * Integrity: children are deleted before parents and every remaining
 * reference to a removed user is nulled, so no foreign key or orphan row is
 * left on any driver. Stored uploads that belonged to cleared rows (proofs,
 * campaign logos, deposit proofs, support attachments, removed users' KYC
 * documents) are deleted best-effort after the transaction.
 *
 * Irreversible by design: down() does nothing.
 */
return new class extends Migration
{
    private const KEEP_EMAILS = [
        'superadmin@ebizearn.com',
        'admin@ebizearn.com',
        'brand@ebizearn.com',
        'sarah@ebizearn.com',
    ];

    /** Cleared completely, in child -> parent order. */
    private const CLEAR_TABLES = [
        'ai_verification_results',
        'submission_files',
        'fraud_events',
        'task_submissions',
        'task_assignments',
        'tasks',
        'referral_rewards',
        'referrals',
        'deposit_requests',
        'withdrawal_requests',
        'wallet_transactions',
        'support_messages',
        'support_tickets',
        'campaigns',
        'audit_logs',
        'email_logs',
        'payment_logs',
        'idempotency_keys',
        'demo_requests',
        'email_otps',
        'email_campaigns',
        'social_channels',
        'personal_access_tokens',
        'password_reset_tokens',
        'sessions',
    ];

    public function up(): void
    {
        $keep = DB::table('users')
            ->whereIn('email', self::KEEP_EMAILS)
            ->orWhere('role', 'superadmin')
            ->pluck('id')
            ->all();

        // Safety: with nothing to keep, "every user not in []" is everyone.
        // Refuse rather than wipe all accounts on a database we don't expect.
        if ($keep === [] && DB::table('users')->exists()) {
            throw new RuntimeException(
                'Reset aborted: none of the test login accounts (' . implode(', ', self::KEEP_EMAILS) . ') and no superadmin were found.'
            );
        }

        // Files to remove once the rows are gone.
        $proofs = $this->column('submission_files', 'file_path');
        $logos = $this->column('campaigns', 'logo_path');
        $kycFiles = [];
        foreach (['kyc_front_path', 'kyc_back_path', 'kyc_selfie_path'] as $col) {
            if (Schema::hasColumn('profiles', $col)) {
                $kycFiles = array_merge($kycFiles, DB::table('profiles')->whereNotIn('user_id', $keep)->whereNotNull($col)->pluck($col)->all());
            }
        }

        DB::transaction(function () use ($keep) {
            foreach (self::CLEAR_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Kept rows must not point at users that are about to go.
            DB::table('users')->whereIn('id', $keep)->update(['referrer_id' => null]);
            if (Schema::hasColumn('profiles', 'kyc_reviewed_by')) {
                DB::table('profiles')->whereNotIn('kyc_reviewed_by', $keep)->update(['kyc_reviewed_by' => null]);
            }
            if (Schema::hasTable('task_templates')) {
                DB::table('task_templates')->whereNotIn('created_by', $keep)->update(['created_by' => null]);
            }

            // Remove every other account and everything it owned.
            foreach (['social_accounts', 'permission_user', 'profiles', 'wallets'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereNotIn('user_id', $keep)->delete();
                }
            }
            DB::table('businesses')->whereNotIn('owner_id', $keep)->delete();
            DB::table('users')->whereNotIn('id', $keep)->delete();

            // Fresh wallets for the kept accounts (the ledger is empty now).
            DB::table('wallets')->update([
                'available_balance_cents' => 0,
                'pending_balance_cents' => 0,
                'lifetime_earnings_cents' => 0,
                'total_withdrawn_cents' => 0,
            ]);
            if (Schema::hasColumn('profiles', 'fraud_score')) {
                DB::table('profiles')->update(['fraud_score' => 0]);
            }
        });

        $this->deleteFiles('public', array_merge($proofs, $logos));
        $this->deleteFiles('local', $kycFiles);
        foreach (['public' => ['proofs', 'campaign-logos'], 'local' => ['deposits', 'support']] as $disk => $dirs) {
            foreach ($dirs as $dir) {
                try {
                    Storage::disk($disk)->deleteDirectory($dir);
                } catch (\Throwable $e) {
                    Log::warning('reset_platform_data: could not clear directory', ['disk' => $disk, 'dir' => $dir, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Cleared data cannot be restored.
    }

    private function column(string $table, string $column): array
    {
        return Schema::hasColumn($table, $column)
            ? DB::table($table)->whereNotNull($column)->pluck($column)->all()
            : [];
    }

    private function deleteFiles(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || str_starts_with($path, 'http')) {
                continue;
            }
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $e) {
                Log::warning('reset_platform_data: could not delete file', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }
};

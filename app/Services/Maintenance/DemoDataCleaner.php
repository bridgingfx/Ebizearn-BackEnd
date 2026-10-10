<?php

namespace App\Services\Maintenance;

use App\Models\User;
use App\Services\Contributors\ContributorTierService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds and removes the demo / sample data the DatabaseSeeder used to
 * create (fake campaigns and tasks, a demo proof submission, and a fake
 * wallet history for the demo contributor) — and only that.
 *
 * "Demo" is matched exactly (seeded campaign titles owned by the seeded
 * business account, seeded ledger descriptions with no reference), never
 * guessed. Records that merely LOOK like tests ("test", "dummy" in a
 * title) are listed for a person to review and are only removed when the
 * caller explicitly opts in.
 *
 * KEPT, always: every user account (contributor, business, admin, super
 * admin) with its profile, business, permissions and login; task
 * categories, task types, Task Library templates and every other
 * configuration table; every campaign / task / payment that isn't demo.
 */
class DemoDataCleaner
{
    /** Campaign titles DatabaseSeeder creates for the seeded business account. */
    public const DEMO_CAMPAIGN_TITLES = [
        'Middle East Tech Launch — Social Awareness',
        'Global Remote Work & Gig Economy Survey 2026',
        'iOS & Android Checkout Usability Evaluation',
        'TikTok & Reels Short UGC Creator Clips',
        'Website Experience Test — Navigation & Speed',
    ];

    public const DEMO_BUSINESS_EMAIL = 'brand@ebizearn.com';
    public const DEMO_CONTRIBUTOR_EMAIL = 'sarah@ebizearn.com';

    /** Fake ledger rows DatabaseSeeder wrote for the demo contributor. */
    public const DEMO_TX_DESCRIPTIONS = [
        'Task Completed: Post Instagram Story',
        'Task Completed: Post in Facebook Group',
        'Task Completed: Product Experience Survey',
        'Referral Bonus: Ayesha K. completed task',
        'Withdrawal to Bank Account (****4821)',
    ];

    /** Words that make a record look like test data (reported, opt-in removal). */
    private const TEST_MARKERS = '/\b(test(ing)?|dummy|demo|sample|lorem|asdf|qwerty|fake)\b/i';

    /**
     * Task & payment activity cleared by allActivity(), child → parent.
     * Accounts and configuration are never in this list.
     */
    public const ACTIVITY_TABLES = [
        'ai_verification_results',
        'submission_files',
        'post_verifications',
        'fraud_events',
        'task_submissions',
        'task_assignments',
        'campaign_media',
        'tasks',
        'referral_rewards',
        'deposit_requests',
        'withdrawal_requests',
        'wallet_transactions',
        'payment_logs',
        'idempotency_keys',
        'notifications',
        'campaigns',
    ];

    /** Row counts allActivity() would clear. */
    public function planAllActivity(): array
    {
        $counts = [];
        foreach (self::ACTIVITY_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Fresh start before launch: clear EVERY campaign, task, submission and
     * payment record. Kept: all user accounts (login, profile, business,
     * permissions, team members, referral links, follows) and all
     * configuration (task categories, task types, Task Library templates,
     * settings, email templates…). Wallets are reset to $0 — with the ledger
     * gone, any balance would be money without history. Irreversible.
     */
    public function allActivity(): array
    {
        $counts = $this->planAllActivity();
        $files = [
            'public' => array_merge($this->paths('submission_files', 'file_path'), $this->paths('campaigns', 'logo_path'), $this->paths('campaigns', 'content_image_path')),
            'local' => $this->paths('deposit_requests', 'proof_path'),
        ];

        DB::transaction(function () use ($counts) {
            foreach (self::ACTIVITY_TABLES as $table) {
                if (isset($counts[$table])) {
                    DB::table($table)->delete();
                }
            }

            // Referral links stay; nothing has been earned through them any more.
            if (Schema::hasTable('referrals')) {
                DB::table('referrals')->update(['status' => 'pending', 'qualified_at' => null]);
            }

            DB::table('wallets')->update([
                'available_balance_cents' => 0,
                'pending_balance_cents' => 0,
                'lifetime_earnings_cents' => 0,
                'total_withdrawn_cents' => 0,
                'updated_at' => now(),
            ]);
            if (Schema::hasColumn('profiles', 'fraud_score')) {
                DB::table('profiles')->update(['fraud_score' => 0]);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => null,
                'action' => 'maintenance.all_activity_cleared',
                'entity_type' => 'maintenance',
                'entity_id' => 0,
                'after_state_json' => json_encode($counts),
                'created_at' => now(),
            ]);
        });

        $tiers = new ContributorTierService();
        User::where('role', 'contributor')->each(fn (User $u) => $tiers->recalculateFor($u));

        // Uploaded proofs, campaign images / videos / logos, deposit receipts.
        foreach ($files as $disk => $paths) {
            foreach ($paths as $path) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($disk)->delete($path);
                } catch (\Throwable) {
                    // best effort — a missing file is fine
                }
            }
        }
        foreach (['public' => ['proofs', 'campaign-logos', 'campaign-content', 'campaign-media'], 'local' => ['deposits']] as $disk => $dirs) {
            foreach ($dirs as $dir) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($disk)->deleteDirectory($dir);
                } catch (\Throwable) {
                }
            }
        }

        return $counts;
    }

    private function paths(string $table, string $column): array
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->whereNotNull($column)->where($column, 'not like', 'http%')->pluck($column)->all()
            : [];
    }

    /**
     * What would be removed / flagged. Nothing is changed.
     *
     * @return array{campaign_ids: int[], marked_campaign_ids: int[], tx_ids: int[], counts: array<string, int>, marked: array, wallets_to_review: array}
     */
    public function plan(bool $includeMarked = false): array
    {
        $demoBusinessIds = DB::table('businesses')
            ->join('users', 'users.id', '=', 'businesses.owner_id')
            ->where('users.email', self::DEMO_BUSINESS_EMAIL)
            ->pluck('businesses.id');

        $demoCampaigns = DB::table('campaigns')
            ->whereIn('business_id', $demoBusinessIds)
            ->whereIn('title', self::DEMO_CAMPAIGN_TITLES)
            ->pluck('id');

        $marked = DB::table('campaigns')
            ->whereNotIn('id', $demoCampaigns)
            ->get(['id', 'title', 'description', 'status', 'business_id', 'created_at'])
            ->filter(fn ($c) => preg_match(self::TEST_MARKERS, $c->title . ' ' . $c->description))
            ->values();

        $campaignIds = $demoCampaigns->merge($includeMarked ? $marked->pluck('id') : [])->unique()->values();

        $taskIds = DB::table('tasks')->whereIn('campaign_id', $campaignIds)->pluck('id');
        $assignmentIds = DB::table('task_assignments')->whereIn('task_id', $taskIds)->pluck('id');
        $submissionIds = DB::table('task_submissions')->whereIn('task_id', $taskIds)->pluck('id');

        $demoWalletIds = DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->where('users.email', self::DEMO_CONTRIBUTOR_EMAIL)
            ->pluck('wallets.id');

        $txIds = DB::table('wallet_transactions')
            ->where(fn ($q) => $q
                // ledger rows of the demo campaigns' submissions / campaigns
                ->where(fn ($w) => $w->where('reference_type', \App\Models\TaskSubmission::class)->whereIn('reference_id', $submissionIds))
                ->orWhere(fn ($w) => $w->where('reference_type', \App\Models\Campaign::class)->whereIn('reference_id', $campaignIds))
                // the seeded fake history (no reference at all)
                ->orWhere(fn ($w) => $w->whereIn('wallet_id', $demoWalletIds)->whereNull('reference_id')->whereIn('description', self::DEMO_TX_DESCRIPTIONS)))
            ->pluck('id');

        $affectedWallets = DB::table('wallet_transactions')->whereIn('id', $txIds)->distinct()->pluck('wallet_id')->merge($demoWalletIds)->unique();
        $walletsToReview = DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->whereIn('wallets.id', $affectedWallets)
            ->get(['wallets.id', 'users.email', 'available_balance_cents', 'pending_balance_cents'])
            ->map(fn ($w) => (array) $w + [
                'other_transactions' => DB::table('wallet_transactions')->where('wallet_id', $w->id)->whereNotIn('id', $txIds)->count(),
            ])
            ->values()
            ->all();

        $count = fn (string $table, string $col, Collection $ids) => Schema::hasTable($table) ? DB::table($table)->whereIn($col, $ids)->count() : 0;

        return [
            'campaign_ids' => $campaignIds->all(),
            'marked_campaign_ids' => $marked->pluck('id')->all(),
            'tx_ids' => $txIds->all(),
            'counts' => [
                'campaigns' => $campaignIds->count(),
                'tasks' => $taskIds->count(),
                'task_assignments' => $assignmentIds->count(),
                'task_submissions' => $submissionIds->count(),
                'submission_files' => $count('submission_files', 'submission_id', $submissionIds),
                'ai_verification_results' => $count('ai_verification_results', 'submission_id', $submissionIds),
                'post_verifications' => $count('post_verifications', 'submission_id', $submissionIds),
                'fraud_events' => $count('fraud_events', 'submission_id', $submissionIds),
                'campaign_media' => $count('campaign_media', 'campaign_id', $campaignIds),
                'wallet_transactions' => $txIds->count(),
            ],
            'marked' => $marked->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'created_at' => $c->created_at])->all(),
            'wallets_to_review' => $walletsToReview,
        ];
    }

    /**
     * Remove what plan() found, in one transaction (children before parents).
     * Wallets left with no ledger rows are zeroed — a balance without history
     * is exactly the fake money being removed. Wallets that still have real
     * rows keep their balance and are returned for a person to check.
     */
    public function run(bool $includeMarked = false): array
    {
        $plan = $this->plan($includeMarked);
        $campaignIds = collect($plan['campaign_ids']);

        DB::transaction(function () use ($plan, $campaignIds) {
            $taskIds = DB::table('tasks')->whereIn('campaign_id', $campaignIds)->pluck('id');
            $submissionIds = DB::table('task_submissions')->whereIn('task_id', $taskIds)->pluck('id');

            DB::table('wallet_transactions')->whereIn('id', $plan['tx_ids'])->delete();

            foreach (['submission_files', 'ai_verification_results', 'post_verifications', 'fraud_events'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('submission_id', $submissionIds)->delete();
                }
            }
            if (Schema::hasTable('notifications')) {
                // In-app notices pointing at the removed tasks.
                foreach (DB::table('tasks')->whereIn('id', $taskIds)->pluck('uuid') as $uuid) {
                    DB::table('notifications')->where('data', 'like', '%' . $uuid . '%')->delete();
                }
            }
            DB::table('task_submissions')->whereIn('id', $submissionIds)->delete();
            DB::table('task_assignments')->whereIn('task_id', $taskIds)->delete();
            DB::table('tasks')->whereIn('id', $taskIds)->delete();
            if (Schema::hasTable('campaign_media')) {
                DB::table('campaign_media')->whereIn('campaign_id', $campaignIds)->delete();
            }
            DB::table('campaigns')->whereIn('id', $campaignIds)->delete();

            foreach ($plan['wallets_to_review'] as $w) {
                if (DB::table('wallet_transactions')->where('wallet_id', $w['id'])->doesntExist()) {
                    DB::table('wallets')->where('id', $w['id'])->update([
                        'available_balance_cents' => 0,
                        'pending_balance_cents' => 0,
                        'lifetime_earnings_cents' => 0,
                        'total_withdrawn_cents' => 0,
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('audit_logs')->insert([
                'actor_id' => null,
                'action' => 'maintenance.demo_data_removed',
                'entity_type' => 'maintenance',
                'entity_id' => 0,
                'after_state_json' => json_encode($plan['counts']),
                'created_at' => now(),
            ]);
        });

        // Contributor stats (completed tasks, approval rate, level) from real history.
        $tiers = new ContributorTierService();
        User::where('role', 'contributor')->each(fn (User $u) => $tiers->recalculateFor($u));

        $plan['wallets_to_review'] = collect($plan['wallets_to_review'])
            ->filter(fn ($w) => DB::table('wallet_transactions')->where('wallet_id', $w['id'])->exists())
            ->values()
            ->all();

        return $plan;
    }
}

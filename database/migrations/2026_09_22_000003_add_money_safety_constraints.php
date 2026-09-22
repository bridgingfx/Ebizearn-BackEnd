<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P0/P1 money-safety hardening:
     *  - unique(task_id, user_id) on task_assignments (kills duplicate-slot race)
     *  - unique(task_id, user_id) on task_submissions (kills double-submit race)
     *  - unique(user_id) on profiles (matches User::profile() HasOne)
     *  - wallet_transactions.type gains task_reward_reversal / referral_reward_reversal
     *    (used by the approve -> reject credit-reversal path)
     *  - task_submissions.triggered_referral_id tracks which approval paid a
     *    referral reward, so a later reject-after-approve can reverse it.
     *
     * Duplicate rows are de-duplicated (keeping the earliest row) before the
     * unique constraints are added so the migration cannot fail on dirty data.
     */
    public function up(): void
    {
        $this->dedupe('task_assignments', ['task_id', 'user_id']);
        $this->dedupe('task_submissions', ['task_id', 'user_id'], 'status');
        $this->dedupe('profiles', ['user_id']);

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'user_id']);
            $table->unique(['task_id', 'user_id']);
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->unique(['task_id', 'user_id']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->unique('user_id');
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->foreignId('triggered_referral_id')
                ->nullable()
                ->after('assignment_id')
                ->constrained('referrals')
                ->nullOnDelete();
        });

        // SQLite compiles enum() to a plain varchar, so the new types work
        // there without an ALTER; only MySQL needs the enum list extended.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM(" .
                "'task_reward','referral_reward','withdrawal','withdrawal_reversal'," .
                "'campaign_funding','campaign_refund','admin_adjustment','bonus'," .
                "'task_reward_reversal','referral_reward_reversal'" .
                ") NOT NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropForeign(['triggered_referral_id']);
            $table->dropColumn('triggered_referral_id');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropUnique(['task_id', 'user_id']);
        });

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropUnique(['task_id', 'user_id']);
            $table->index(['task_id', 'user_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM(" .
                "'task_reward','referral_reward','withdrawal','withdrawal_reversal'," .
                "'campaign_funding','campaign_refund','admin_adjustment','bonus'" .
                ") NOT NULL"
            );
        }
    }

    /**
     * Delete duplicate rows on the given key columns. For submissions, an
     * approved row is kept over any other status (it carries the money trail);
     * otherwise the earliest row wins. Uses portable queries so it works on
     * both MySQL and SQLite.
     */
    protected function dedupe(string $table, array $columns, ?string $statusColumn = null): void
    {
        $query = DB::table($table)->select(array_merge($columns, ['id']));

        if ($statusColumn) {
            $query->addSelect($statusColumn);
        }

        $groups = $query->get()->groupBy(
            fn ($row) => implode('|', array_map(fn ($c) => (string) $row->{$c}, $columns))
        );

        $statusPriority = [
            'approved' => 0,
            'under_review' => 1,
            'submitted' => 2,
            'action_required' => 3,
            'rejected' => 4,
        ];

        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }

            $sorted = $statusColumn
                ? $group->sortBy(fn ($row) => [($statusPriority[$row->{$statusColumn}] ?? 9), $row->id])
                : $group->sortBy('id');

            $ids = $sorted->pluck('id')->all();
            array_shift($ids); // keep the first (winner), delete the rest

            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }
};

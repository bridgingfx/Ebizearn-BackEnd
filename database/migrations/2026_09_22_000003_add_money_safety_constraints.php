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

        // The plain composite index this replaces may not exist under Laravel's
        // conventional name on every environment (schema history differs per
        // deploy target), so drop it defensively rather than assuming it's there.
        Schema::table('task_assignments', function (Blueprint $table) {
            try {
                $table->dropIndex(['task_id', 'user_id']);
            } catch (\Throwable $e) {
                // Nothing to drop under that name; the unique constraint below still applies.
            }
        });

        Schema::table('task_assignments', function (Blueprint $table) {
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

        // wallet_transactions.type gains the reversal types used by the
        // approve -> reject credit-reversal path.
        // SQLite compiles enum() to a CHECK constraint, so the table must be
        // rebuilt there; only MySQL can extend a native ENUM in place.
        $extendedTypes = [
            'task_reward', 'referral_reward', 'withdrawal', 'withdrawal_reversal',
            'campaign_funding', 'campaign_refund', 'admin_adjustment', 'bonus',
            'task_reward_reversal', 'referral_reward_reversal',
        ];

        if (DB::getDriverName() === 'mysql') {
            $this->setMysqlTransactionTypeEnum($extendedTypes);
        } else {
            $this->rebuildTransactionTypeEnum($extendedTypes);
        }
    }

    /**
     * Shrink the wallet_transactions.type enum back to the original list.
     */
    protected function originalTransactionTypes(): array
    {
        return [
            'task_reward', 'referral_reward', 'withdrawal', 'withdrawal_reversal',
            'campaign_funding', 'campaign_refund', 'admin_adjustment', 'bonus',
        ];
    }

    protected function setMysqlTransactionTypeEnum(array $types): void
    {
        $list = implode(',', array_map(fn ($t) => "'{$t}'", $types));
        DB::statement("ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM({$list}) NOT NULL");
    }

    /**
     * SQLite cannot alter a CHECK constraint, so rebuild the table with the
     * extended enum values, copying all rows and indexes across. No other
     * table holds a foreign key to wallet_transactions, so the drop is safe.
     */
    protected function rebuildTransactionTypeEnum(array $types): void
    {
        $table = 'wallet_transactions';
        $temp = $table . '_enum_rebuild';

        Schema::create($temp, function (Blueprint $blueprint) use ($types) {
            $blueprint->id();
            $blueprint->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $blueprint->enum('type', $types);
            $blueprint->bigInteger('amount_cents');
            $blueprint->bigInteger('balance_after_cents');
            $blueprint->string('currency', 4)->default('USD');
            $blueprint->string('reference_type')->nullable();
            $blueprint->unsignedBigInteger('reference_id')->nullable();
            $blueprint->string('description');
            $blueprint->json('metadata_json')->nullable();
            $blueprint->timestamp('created_at')->useCurrent();
        });

        $columns = [
            'id', 'wallet_id', 'type', 'amount_cents', 'balance_after_cents',
            'currency', 'reference_type', 'reference_id', 'description',
            'metadata_json', 'created_at',
        ];
        $list = implode(',', array_map(fn ($c) => "\"{$c}\"", $columns));
        DB::statement("INSERT INTO \"{$temp}\" ({$list}) SELECT {$list} FROM \"{$table}\"");

        Schema::drop($table);
        Schema::rename($temp, $table);

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->index(['wallet_id', 'created_at']);
            $blueprint->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        // Reversal rows cannot survive the enum shrink below.
        DB::table('wallet_transactions')
            ->whereIn('type', ['task_reward_reversal', 'referral_reward_reversal'])
            ->delete();

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
            $this->setMysqlTransactionTypeEnum($this->originalTransactionTypes());
        } else {
            $this->rebuildTransactionTypeEnum($this->originalTransactionTypes());
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

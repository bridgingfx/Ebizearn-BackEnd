<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business deposits (card link, crypto, bank transfer, email request) are
 * credited to the business wallet as a `deposit` transaction once staff
 * approve them. Same enum rebuild pattern as 000015 (SQLite cannot alter a
 * CHECK constraint; MySQL modifies the ENUM).
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = [
            'task_reward', 'referral_reward', 'withdrawal', 'withdrawal_reversal',
            'campaign_funding', 'campaign_refund', 'admin_adjustment', 'bonus',
            'task_reward_reversal', 'referral_reward_reversal',
            'retention_hold', 'retention_release', 'retention_hold_cancel',
            'deposit',
        ];

        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($t) => "'{$t}'", $types));
            DB::statement("ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM({$list}) NOT NULL");
        } else {
            $this->rebuildTransactionTypeEnum($types);
        }
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->where('type', 'deposit')
            ->delete();

        $types = [
            'task_reward', 'referral_reward', 'withdrawal', 'withdrawal_reversal',
            'campaign_funding', 'campaign_refund', 'admin_adjustment', 'bonus',
            'task_reward_reversal', 'referral_reward_reversal',
            'retention_hold', 'retention_release', 'retention_hold_cancel',
        ];

        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($t) => "'{$t}'", $types));
            DB::statement("ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM({$list}) NOT NULL");
        } else {
            $this->rebuildTransactionTypeEnum($types);
        }
    }

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
};

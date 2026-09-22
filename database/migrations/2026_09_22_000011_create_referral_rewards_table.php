<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 (3-level affiliate): ledger-facing referral reward records.
     *
     * One row per (referrer, referee, level). The unique key is the
     * double-pay guard: a referral level can be rewarded at most once, even
     * across retries or concurrent qualification attempts. The
     * wallet_transaction_id unique link ties each reward to exactly one
     * ledger entry.
     */
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->enum('status', ['pending', 'rewarded', 'reversed'])->default('pending');
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();

            $table->unique(['referrer_id', 'referred_user_id', 'level'], 'referral_rewards_referrer_referee_level_unique');
            $table->index(['referrer_id', 'status']);
            $table->index(['referred_user_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};

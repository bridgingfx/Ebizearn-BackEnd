<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-controllable referral reward rules (one row per affiliate level).
 *
 * Each level supports two reward modes:
 *  - flat:    a fixed amount in cents (reward_cents), e.g. L1 = 100 ($1.00)
 *  - percent: a percentage of the referee's first approved task reward,
 *             stored as basis points in percent_bps (1000 = 10%).
 *
 * Defaults: flat mode, L1 $1.00 / L2 $0.50 / L3 $0.25, with 10%/5%/2%
 * percent values ready if an admin switches a level to percent mode.
 * Seeded by DatabaseSeeder::seedReferralRules(); the rules table is the
 * source of truth, config('referrals') is the fallback.
 *
 * Rollback-safe on SQLite and MySQL: plain create/drop table, no alters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->string('reward_mode', 10)->default('flat'); // flat|percent
            $table->integer('reward_cents')->nullable();       // flat-mode amount
            $table->integer('percent_bps')->default(0);         // percent-mode, 1000 = 10%
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rules');
    }
};

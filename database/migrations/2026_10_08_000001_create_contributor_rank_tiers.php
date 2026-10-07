<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contributor rank tiers: starter → contributor → trusted → pro → elite.
     * Super Admin customizes the promotion thresholds (tasks completed,
     * total earned) and the bonus % each rank earns on top of task rewards.
     * Default: each rank up = +5% bonus (Dawood's standard).
     */
    public function up(): void
    {
        Schema::create('contributor_rank_tiers', function (Blueprint $table) {
            $table->id();
            // Must match the profile contributor_level enum values.
            $table->string('level', 20)->unique();
            $table->string('display_name', 50);
            $table->unsignedInteger('sort_order')->default(0);
            // Promotion thresholds — contributor auto-promotes when BOTH are met.
            // 0 = no requirement for that metric.
            $table->unsignedInteger('required_tasks')->default(0);
            $table->unsignedBigInteger('required_earnings_cents')->default(0);
            // Bonus % added on top of every task reward at this rank (e.g. 5 = +5%).
            $table->decimal('bonus_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the 5 default tiers (Dawood's standard: +5% per rank).
        $now = now();
        $tiers = [
            ['level' => 'starter',     'display_name' => 'Starter',     'sort_order' => 1, 'required_tasks' => 0,   'required_earnings_cents' => 0,      'bonus_percent' => 0],
            ['level' => 'explorer',    'display_name' => 'Contributor', 'sort_order' => 2, 'required_tasks' => 10,  'required_earnings_cents' => 500,    'bonus_percent' => 5],
            ['level' => 'trusted',     'display_name' => 'Trusted',     'sort_order' => 3, 'required_tasks' => 50,  'required_earnings_cents' => 5000,   'bonus_percent' => 10],
            ['level' => 'pro',         'display_name' => 'Pro',         'sort_order' => 4, 'required_tasks' => 200, 'required_earnings_cents' => 25000,  'bonus_percent' => 15],
            ['level' => 'elite',       'display_name' => 'Elite',       'sort_order' => 5, 'required_tasks' => 500, 'required_earnings_cents' => 100000, 'bonus_percent' => 20],
        ];
        foreach ($tiers as $t) {
            DB::table('contributor_rank_tiers')->insert(array_merge($t, [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contributor_rank_tiers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: task-type system.
 *
 * One row per marketplace task type (follow, like_comment, share, watch,
 * download/app_test, survey, ugc, referral, community). Each type carries
 * its proof contract, retention default, fraud rules, allowed platforms,
 * an `is_allowed` flag (e.g. like/comment only where platform policy
 * permits incentivized engagement), and the enforceable reward band —
 * task rewards outside the band are rejected with 422 (Phase 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_allowed')->default(true);
            $table->string('policy_note')->nullable();
            $table->json('proof_required_json')->nullable(); // e.g. ["screenshot","url"]
            $table->unsignedInteger('retention_period_days')->default(0);
            $table->json('fraud_rules_json')->nullable();
            $table->unsignedBigInteger('reward_band_min_cents')->default(0);
            $table->unsignedBigInteger('reward_band_max_cents')->default(0);
            $table->json('allowed_platforms_json')->nullable(); // e.g. ["instagram","tiktok"]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_types');
    }
};

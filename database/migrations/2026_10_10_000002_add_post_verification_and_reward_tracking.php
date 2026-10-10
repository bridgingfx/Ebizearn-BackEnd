<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram post verification + pending-reward lifecycle.
 *
 * task_submissions
 * - auto_verify_status: the automatic check (AI vision + Instagram API) —
 *   pending → done | skipped. It never blocks manual review.
 * - platform_media_id / platform_post_url / platform_posted_at: the post the
 *   official Instagram API confirmed (used again at the final check).
 * - reward_status: pending_duration → released | refunded, or
 *   reverification_required when the final check can't confirm the post.
 * - final_check_due_at / final_check_attempts / final_checked_at: the end
 *   of the task duration and the retry policy for the final check.
 * - funding_*: where the reward came from (the business wallet that funded
 *   the campaign escrow), so a failed task refunds the right account.
 * - hold_tx_id / release_tx_id / refund_tx_id: the ledger rows that tie the
 *   reservation, release and refund together (each happens at most once).
 *
 * post_verifications: every verification attempt (initial and final) with
 * the API checks, the AI verdict and the reason.
 *
 * social_channels.oauth_scopes: the permissions the user granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->string('auto_verify_status', 16)->nullable()->index();
            $table->timestamp('auto_verified_at')->nullable();
            $table->string('platform_media_id', 64)->nullable();
            $table->string('platform_post_url', 500)->nullable();
            $table->timestamp('platform_posted_at')->nullable();
            $table->string('reward_status', 32)->nullable()->index();
            $table->timestamp('final_check_due_at')->nullable()->index();
            $table->unsignedInteger('final_check_attempts')->default(0);
            $table->timestamp('final_checked_at')->nullable();
            $table->string('funding_type', 32)->nullable();
            $table->unsignedBigInteger('funding_user_id')->nullable();
            $table->unsignedBigInteger('funding_wallet_id')->nullable();
            $table->string('funding_reference', 120)->nullable();
            $table->unsignedBigInteger('hold_tx_id')->nullable();
            $table->unsignedBigInteger('release_tx_id')->nullable();
            $table->unsignedBigInteger('refund_tx_id')->nullable();
        });

        Schema::create('post_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('task_submissions')->cascadeOnDelete();
            $table->string('stage', 16);   // initial | final | manual
            $table->string('outcome', 16); // verified | failed | inconclusive | skipped
            $table->string('reason', 500)->nullable();
            $table->json('api_checks_json')->nullable();
            $table->json('ai_json')->nullable();
            $table->json('api_meta_json')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['submission_id', 'stage']);
        });

        Schema::table('social_channels', function (Blueprint $table) {
            $table->string('oauth_scopes', 500)->nullable();
        });

        // Rewards already sitting in a retention hold are "pending duration".
        $held = DB::table('wallet_transactions')
            ->where('type', 'retention_hold')
            ->where('reference_type', \App\Models\TaskSubmission::class)
            ->pluck('reference_id');
        if ($held->isNotEmpty()) {
            DB::table('task_submissions')->whereIn('id', $held)->where('status', 'approved')
                ->update(['reward_status' => 'pending_duration']);
        }
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropColumn('oauth_scopes');
        });
        Schema::dropIfExists('post_verifications');
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropIndex(['auto_verify_status']);
            $table->dropIndex(['reward_status']);
            $table->dropIndex(['final_check_due_at']);
            $table->dropColumn([
                'auto_verify_status', 'auto_verified_at', 'platform_media_id', 'platform_post_url', 'platform_posted_at',
                'reward_status', 'final_check_due_at', 'final_check_attempts', 'final_checked_at',
                'funding_type', 'funding_user_id', 'funding_wallet_id', 'funding_reference',
                'hold_tx_id', 'release_tx_id', 'refund_tx_id',
            ]);
        });
    }
};

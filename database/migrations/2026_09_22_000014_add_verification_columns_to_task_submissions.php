<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: verification evidence on task_submissions.
 *
 * - `checking` joins the status enum: the forward-only machine is
 *   submitted -> checking (system/heuristic + fraud screens) ->
 *   under_review (moderator) -> approved | rejected | action_required.
 * - `proof_hash`: SHA-256 of the submitted proof payload (screenshot bytes
 *   or proof URL). Duplicate hashes are rejected per campaign.
 * - `device_fingerprint`: SHA-256(ip + user_agent) for multi-account screens.
 * - `review_reason_code`: mandatory reason code recorded with each decision.
 * - `triggered_referral_reward_ids_json`: ReferralReward ids this approval
 *   paid (multi-level), so reject-after-approve can reverse exactly them.
 * - `verification_stage`: human-readable pipeline stage
 *   (received|checking|moderator_review|decided).
 *
 * SQLite cannot ALTER an enum, so the table is rebuilt (same pattern as
 * 2026_09_22_000010_rebuild_referrals_for_multilevel). MySQL takes the
 * direct path.
 */
return new class extends Migration
{
    private const STATUSES = ['submitted', 'checking', 'under_review', 'approved', 'rejected', 'action_required'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('task_submissions', function (Blueprint $table) {
                $table->enum('status', self::STATUSES)->default('submitted')->change();
            });

            $this->addColumns();

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('task_submissions_rebuild', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('task_assignments')->nullOnDelete();
            $table->foreignId('triggered_referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->enum('status', self::STATUSES)->default('submitted');
            $table->json('proof_data_json')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('proof_hash', 64)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('review_reason_code', 64)->nullable();
            $table->json('triggered_referral_reward_ids_json')->nullable();
            $table->string('verification_stage', 32)->default('received');
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index(['task_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['proof_hash']);
        });

        DB::statement(
            'INSERT INTO "task_submissions_rebuild" ("id", "uuid", "task_id", "user_id", "assignment_id", "triggered_referral_id", "status", "proof_data_json", "reviewer_id", "reviewed_at", "review_notes", "created_at", "updated_at") ' .
            'SELECT "id", "uuid", "task_id", "user_id", "assignment_id", "triggered_referral_id", "status", "proof_data_json", "reviewer_id", "reviewed_at", "review_notes", "created_at", "updated_at" FROM "task_submissions"'
        );

        Schema::drop('task_submissions');
        Schema::rename('task_submissions_rebuild', 'task_submissions');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('task_submissions', function (Blueprint $table) {
                $table->dropColumn([
                    'proof_hash',
                    'device_fingerprint',
                    'review_reason_code',
                    'triggered_referral_reward_ids_json',
                    'verification_stage',
                ]);
                $table->enum('status', ['submitted', 'under_review', 'approved', 'rejected', 'action_required'])->default('submitted')->change();
            });

            return;
        }

        // SQLite: drop the added columns; keep `checking` rows mapped to
        // `under_review` so no data is lost on rollback.
        DB::statement("UPDATE task_submissions SET status = 'under_review' WHERE status = 'checking'");
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'proof_hash',
                'device_fingerprint',
                'review_reason_code',
                'triggered_referral_reward_ids_json',
                'verification_stage',
            ]);
        });
    }

    private function addColumns(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->string('proof_hash', 64)->nullable()->after('review_notes');
            $table->string('device_fingerprint', 64)->nullable()->after('proof_hash');
            $table->string('review_reason_code', 64)->nullable()->after('device_fingerprint');
            $table->json('triggered_referral_reward_ids_json')->nullable()->after('review_reason_code');
            $table->string('verification_stage', 32)->default('received')->after('triggered_referral_reward_ids_json');
            $table->index(['proof_hash']);
        });
    }
};

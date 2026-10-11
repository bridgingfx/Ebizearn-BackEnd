<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
<<<<<<< Updated upstream
 * Verification Agent (fraud & risk, AI evidence).
=======
 * Verification Agent (fraud & risk, AI evidence, appeals).
>>>>>>> Stashed changes
 *
 * - submission_files.phash: 64-bit perceptual fingerprint of a proof
 *   screenshot. Unlike the byte hash it survives re-saving, resizing and
 *   light cropping, so the same picture reused by another account is found.
 * - post_verifications.risk_json: the risk engine's flags and score for
 *   that check (reused image, account switching, coordinated abuse…).
<<<<<<< Updated upstream
 *
 * (submission_appeals is created by 2026_10_11_000001_create_submission_appeals_table.)
=======
 * - submission_appeals: a contributor contests a rejection once. Staff
 *   uphold it (the proof goes back to the review queue — never approved
 *   or paid automatically) or deny it (the rejection stands).
>>>>>>> Stashed changes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_files', function (Blueprint $table) {
            $table->string('phash', 16)->nullable()->index();
        });

        Schema::table('post_verifications', function (Blueprint $table) {
            $table->json('risk_json')->nullable();
        });
<<<<<<< Updated upstream
=======

        Schema::create('submission_appeals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('submission_id')->unique()->constrained('task_submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('open')->index(); // open | upheld | denied
            $table->text('review_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
>>>>>>> Stashed changes
    }

    public function down(): void
    {
<<<<<<< Updated upstream
=======
        Schema::dropIfExists('submission_appeals');
>>>>>>> Stashed changes
        Schema::table('post_verifications', function (Blueprint $table) {
            $table->dropColumn('risk_json');
        });
        Schema::table('submission_files', function (Blueprint $table) {
            $table->dropIndex(['phash']);
            $table->dropColumn('phash');
        });
    }
};

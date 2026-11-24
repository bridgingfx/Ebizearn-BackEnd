<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verification Agent (fraud & risk, AI evidence).
 *
 * - submission_files.phash: 64-bit perceptual fingerprint of a proof
 *   screenshot. Unlike the byte hash it survives re-saving, resizing and
 *   light cropping, so the same picture reused by another account is found.
 * - post_verifications.risk_json: the risk engine's flags and score for
 *   that check (reused image, account switching, coordinated abuse…).
 *
 * (submission_appeals is created by 2026_10_11_000001_create_submission_appeals_table.)
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
    }

    public function down(): void
    {
        Schema::table('post_verifications', function (Blueprint $table) {
            $table->dropColumn('risk_json');
        });
        Schema::table('submission_files', function (Blueprint $table) {
            $table->dropIndex(['phash']);
            $table->dropColumn('phash');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contributor social channels (Instagram, TikTok, YouTube, Facebook, X) the
 * contributor completes tasks with. Ownership is proven with a bio code:
 * the contributor puts `verification_code` in the profile bio, submits, and
 * staff (review_kyc) approve after checking the public profile.
 *
 * Separate from social_accounts, which holds Google/Apple *login* identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 20); // instagram | tiktok | youtube | facebook | x
            $table->string('handle', 120);
            $table->string('profile_url', 500);
            $table->unsignedInteger('followers')->nullable();
            $table->string('verification_code', 20);
            $table->string('status', 20)->default('unverified'); // unverified | pending | verified | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'platform']);
            $table->index(['platform', 'handle']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_channels');
    }
};

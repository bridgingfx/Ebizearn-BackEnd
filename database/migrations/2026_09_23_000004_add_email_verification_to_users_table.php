<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 — email verification tokens.
 *
 * The raw token is handed to the user inside the verification email URL and
 * is NEVER persisted. Only its SHA-256 digest is stored here, so a database
 * read alone can never verify someone else's address.
 * email_verification_sent_at bounds the token lifetime (24h policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_sent_at')->nullable()->after('email_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verification_token', 'email_verification_sent_at']);
        });
    }
};

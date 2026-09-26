<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent proof — terms-of-service acceptance on the account.
 *
 * Records WHICH terms version the user accepted (`terms_version`), WHEN
 * (`terms_accepted_at`) and from WHICH IP (`terms_accepted_ip`).
 * Set at the moment the account is actually activated:
 *  - email signup: POST /api/v1/auth/otp/verify (pending -> active),
 *  - Google / Apple signup: at first-time account creation
 *    (AuthController@socialLogin).
 *
 * All columns are NULLABLE so this migrates safely on production with
 * existing users: legacy accounts simply have no consent proof (never
 * backfilled — a missing record is honest, a fabricated one is not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_version', 16)->nullable()->after('phone');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
            $table->string('terms_accepted_ip', 64)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at', 'terms_accepted_ip']);
        });
    }
};

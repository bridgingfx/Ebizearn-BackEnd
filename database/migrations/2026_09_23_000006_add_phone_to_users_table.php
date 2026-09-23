<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signup hardening — phone number on the account.
 *
 * DESIGN DECISION: a single E.164 `phone` column (e.g. "+971501234567")
 * rather than separate country-code/number columns. Rationale:
 *  - one source of truth (no drift between two columns),
 *  - trivially comparable / searchable / unique-able,
 *  - the registration API still collects `phone_country_code` +
 *    `phone_number` as two validated fields and normalizes them to E.164
 *    before persisting (see AuthController@register + config/phone.php).
 *
 * All columns are NULLABLE so this migrates safely on production with
 * existing users (email signups before this change + Google OAuth users
 * simply have no phone until they add one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};

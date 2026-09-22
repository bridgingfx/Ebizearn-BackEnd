<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Priority 7) — login/device risk tracking on users.
 *
 * - last_login_ip / last_login_at: updated on every successful login so
 *   moderators can spot account sharing and IP anomalies without digging
 *   through the fraud_events log.
 * - registration_ip: captured at signup; the duplicate-account screen
 *   counts distinct users per IP/fingerprint window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_login_ip', 45)->nullable()->after('remember_token');
            $table->timestamp('last_login_at')->nullable()->after('last_login_ip');
            $table->string('registration_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_ip', 'last_login_at', 'registration_ip']);
        });
    }
};

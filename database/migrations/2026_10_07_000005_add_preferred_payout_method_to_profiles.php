<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contributor's preferred payout rail (paypal|wise|bank|usdt), chosen on
 * the profile Payout Methods tab. Additive — nullable, defaults to null
 * (no preference yet). The withdrawal flow still collects the actual
 * account details per withdrawal; this is the default rail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('preferred_payout_method', 20)->nullable()->after('kyc_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('preferred_payout_method');
        });
    }
};

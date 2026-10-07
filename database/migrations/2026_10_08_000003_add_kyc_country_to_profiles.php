<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which country a KYC verification belongs to.
     * When the user changes their country of residence, KYC must be
     * redone with documents from the new country before tasks unlock.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('kyc_country_code', 2)->nullable()->after('kyc_status');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('kyc_country_code');
        });
    }
};

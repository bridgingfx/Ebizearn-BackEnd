<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC provider fields (additive — existing manual KYC columns untouched).
 *
 * kyc_method: 'manual' (default, existing document-upload flow) or 'sumsub'.
 * kyc_provider_ref: Sumsub applicant ID.
 * kyc_provider_status: last review status reported by Sumsub
 *   (e.g. init, pending, queued, completed / GREEN / RED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('kyc_method', 16)->default('manual')->after('kyc_status');
            $table->string('kyc_provider_ref', 128)->nullable()->after('kyc_method');
            $table->string('kyc_provider_status', 64)->nullable()->after('kyc_provider_ref');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['kyc_method', 'kyc_provider_ref', 'kyc_provider_status']);
        });
    }
};

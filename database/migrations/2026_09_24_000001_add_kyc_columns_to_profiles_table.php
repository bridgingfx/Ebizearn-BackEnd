<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC submission + staff review. Documents live on the private `local` disk
 * (never public) and are only streamed to staff through the review API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('profiles', 'kyc_status')) {
                // unverified | pending | verified | rejected
                $table->string('kyc_status', 20)->default('unverified')->index();
            }
            if (!Schema::hasColumn('profiles', 'kyc_document_type')) {
                $table->string('kyc_document_type', 32)->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_front_path')) {
                $table->string('kyc_front_path')->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_back_path')) {
                $table->string('kyc_back_path')->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_selfie_path')) {
                $table->string('kyc_selfie_path')->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_submitted_at')) {
                $table->timestamp('kyc_submitted_at')->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_verified_at')) {
                $table->timestamp('kyc_verified_at')->nullable();
            }
            if (!Schema::hasColumn('profiles', 'kyc_reviewed_by')) {
                $table->foreignId('kyc_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('profiles', 'kyc_rejection_reason')) {
                $table->string('kyc_rejection_reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_reviewed_by');
            $table->dropColumn([
                'kyc_status',
                'kyc_document_type',
                'kyc_front_path',
                'kyc_back_path',
                'kyc_selfie_path',
                'kyc_submitted_at',
                'kyc_verified_at',
                'kyc_rejection_reason',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KYC submissions saved their documents but kept kyc_status "unverified"
 * (the status was not mass-assignable, so update() dropped it). Put those
 * submissions into the staff review queue as "pending".
 *
 * Skipped: users whose KYC was reset by an approved residence-country
 * change after they submitted — they really must submit again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $query = DB::table('profiles')
            ->where('kyc_status', 'unverified')
            ->whereNotNull('kyc_submitted_at')
            ->whereNotNull('kyc_front_path')
            ->whereNull('kyc_verified_at');

        if (Schema::hasTable('country_change_requests')) {
            $query->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('country_change_requests')
                    ->whereColumn('country_change_requests.user_id', 'profiles.user_id')
                    ->where('country_change_requests.status', 'approved')
                    ->whereColumn('country_change_requests.reviewed_at', '>', 'profiles.kyc_submitted_at');
            });
        }

        $query->update(['kyc_status' => 'pending']);
    }

    public function down(): void
    {
        // Not reversible: pending submissions may already have been reviewed.
    }
};

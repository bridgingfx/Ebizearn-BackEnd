<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * YouTube channels connected with Google login were saved with platform
 * "google", so they never showed on the YouTube row of the profile. They
 * are YouTube channels — rename them. If the user also has a manual
 * (bio-code) YouTube row, the officially connected one replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('social_channels')->where('platform', 'google')->get(['id', 'user_id']) as $row) {
            DB::table('social_channels')->where('user_id', $row->user_id)->where('platform', 'youtube')->delete();
            DB::table('social_channels')->where('id', $row->id)->update(['platform' => 'youtube']);
        }
    }

    public function down(): void
    {
    }
};

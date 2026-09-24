<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Referral commissions move from the broad manage_settings permission to a
 * dedicated manage_referral_rules permission that NO role holds by default:
 * Super Admin (implicit) sets L1/L2/L3 commissions, and an admin can do so
 * only after Super Admin grants it (role matrix or per-user override).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => 'manage_referral_rules'],
            ['label' => 'Change referral commissions (L1 / L2 / L3)', 'updated_at' => $now]
        );
        DB::table('permissions')->where('name', 'manage_referral_rules')->whereNull('created_at')->update(['created_at' => $now]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'manage_referral_rules')->delete();
    }
};

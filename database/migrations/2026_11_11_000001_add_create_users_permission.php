<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * create_users: create accounts of any role from Users & KYC.
 *
 * Super Admin always holds it (and is the only one who can create admin /
 * super admin accounts). No role is granted it by default: Super Admin
 * switches it on for the admin / moderator role, or per user, in Roles &
 * Permissions. Staff holding it can only create roles below their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => 'create_users'],
            ['label' => 'Create user accounts from Users & KYC (roles below own)', 'updated_at' => $now]
        );
        DB::table('permissions')->where('name', 'create_users')->whereNull('created_at')->update(['created_at' => $now]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'create_users')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permission_user')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};

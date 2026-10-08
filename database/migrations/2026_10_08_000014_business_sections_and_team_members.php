<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business sidebar sections get their own permissions (Campaigns,
 * Analytics, Billing, Team Access), granted to the business role so every
 * existing business keeps its pages.
 *
 * Team members: a business user whose business_owner_id points at the
 * owner works on the owner's business with the access the owner gives.
 */
return new class extends Migration
{
    private const NEW = [
        Permission::VIEW_OWN_CAMPAIGNS,
        Permission::VIEW_BUSINESS_ANALYTICS,
        Permission::VIEW_BILLING,
        Permission::MANAGE_TEAM,
    ];

    public function up(): void
    {
        $now = now();
        $defs = Permission::definitions();

        foreach (self::NEW as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $defs[$name][0], 'updated_at' => $now]);
            DB::table('permissions')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        $roleId = DB::table('roles')->where('name', 'business')->value('id');
        if ($roleId) {
            foreach (DB::table('permissions')->whereIn('name', self::NEW)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (!Schema::hasColumn('users', 'business_owner_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('business_owner_id')->nullable()->after('managed_by')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'business_owner_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_owner_id');
            });
        }

        $ids = DB::table('permissions')->whereIn('name', self::NEW)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One permission per admin sidebar section, so Super Admin can switch each
 * page per role / per account. Each new permission is mirrored from the
 * shared permission that gated the page before (role grants and per-user
 * allow/deny overrides), so nobody gains or loses access by deploying.
 *
 * Website Traffic and System Health had no permission check at all — the
 * admin role gets them. Wallet view / adjust are new powers: not granted
 * to anyone until Super Admin decides.
 */
return new class extends Migration
{
    /** new permission => the shared permission it is split out of */
    private const MIRROR = [
        Permission::MANAGE_BUSINESSES => Permission::MANAGE_USERS,
        Permission::REVIEW_SOCIAL_CHANNELS => Permission::REVIEW_KYC,
        Permission::PROCESS_DEPOSITS => Permission::PROCESS_PAYOUTS,
        Permission::VIEW_FRAUD => Permission::REVIEW_SUBMISSIONS,
        Permission::VIEW_REFERRALS => Permission::VIEW_REPORTS,
        Permission::VIEW_DEMO_REQUESTS => Permission::VIEW_REPORTS,
        Permission::VIEW_ANALYTICS => Permission::VIEW_REPORTS,
        Permission::VIEW_AUDIT_LOGS => Permission::VIEW_REPORTS,
    ];

    /** Previously ungated pages: granted to the admin role. */
    private const ADMIN_ROLE = [Permission::VIEW_TRAFFIC, Permission::VIEW_SYSTEM_HEALTH];

    private const NEW_ONLY = [Permission::VIEW_WALLETS, Permission::ADJUST_WALLETS];

    public function up(): void
    {
        $now = now();
        $defs = Permission::definitions();

        foreach (array_merge(array_keys(self::MIRROR), self::ADMIN_ROLE, self::NEW_ONLY) as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $defs[$name][0], 'updated_at' => $now]);
            DB::table('permissions')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        $permIds = DB::table('permissions')->pluck('id', 'name');
        $roleIds = DB::table('roles')->pluck('id', 'name');

        foreach (self::MIRROR as $new => $source) {
            if (!isset($permIds[$source])) {
                continue;
            }
            foreach (DB::table('permission_role')->where('permission_id', $permIds[$source])->pluck('role_id') as $roleId) {
                $this->grantRole($roleId, $permIds[$new], $now);
            }
            foreach (DB::table('permission_user')->where('permission_id', $permIds[$source])->get(['user_id', 'is_denied']) as $o) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $o->user_id,
                    'permission_id' => $permIds[$new],
                    'is_denied' => (bool) $o->is_denied,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (isset($roleIds['admin'])) {
            foreach (self::ADMIN_ROLE as $name) {
                $this->grantRole($roleIds['admin'], $permIds[$name], $now);
            }
        }
    }

    private function grantRole(int $roleId, int $permissionId, $now): void
    {
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $names = array_merge(array_keys(self::MIRROR), self::ADMIN_ROLE, self::NEW_ONLY);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

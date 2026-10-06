<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task and campaign permissions become granular so Super Admin can switch
 * each action per role (or per user):
 *
 * - manage_task_templates now only opens the staff task list; new
 *   create_tasks / edit_tasks / delete_tasks gate the actions.
 * - manage_campaigns (already in the catalog, previously unenforced) opens
 *   campaign oversight; new post_campaigns gates creating a campaign for a
 *   business. edit_campaigns / delete_campaigns already exist.
 * - create_business_users: create business accounts from the admin panel.
 *
 * Nothing anyone could do before is lost: every role or user that held
 * manage_task_templates (which used to cover tasks AND campaigns) receives
 * the matching new grants, and a per-user deny of it is mirrored as denies.
 * Admin and moderator roles also get create / edit / delete tasks.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'create_tasks' => 'Create tasks',
        'edit_tasks' => 'Edit tasks (details, pause / resume)',
        'delete_tasks' => 'Delete tasks (no contributor activity only)',
        'post_campaigns' => 'Create campaigns for a business',
        'create_business_users' => 'Create business user accounts',
    ];

    private const RELABEL = [
        'manage_task_templates' => 'Access tasks (view the task list)',
        'manage_campaigns' => 'Access campaigns (view, pause / resume, approve)',
    ];

    /** What holding manage_task_templates used to cover. */
    private const MIRROR = ['manage_campaigns', 'post_campaigns', 'create_tasks', 'edit_tasks', 'delete_tasks'];

    private const ROLE_GRANTS = [
        'admin' => ['manage_task_templates', 'create_tasks', 'edit_tasks', 'delete_tasks', 'manage_campaigns', 'post_campaigns', 'create_business_users'],
        'moderator' => ['manage_task_templates', 'create_tasks', 'edit_tasks', 'delete_tasks', 'manage_campaigns', 'post_campaigns'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::NEW_PERMISSIONS + self::RELABEL as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'updated_at' => $now]);
            DB::table('permissions')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        $permIds = DB::table('permissions')->pluck('id', 'name');
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $source = $permIds['manage_task_templates'];

        // Roles: whoever held the old combined permission keeps every action.
        $roles = DB::table('permission_role')->where('permission_id', $source)->pluck('role_id')->all();
        foreach ($roles as $roleId) {
            foreach (self::MIRROR as $name) {
                $this->grantRole($roleId, $permIds[$name], $now);
            }
        }
        foreach (self::ROLE_GRANTS as $role => $names) {
            if (!isset($roleIds[$role])) {
                continue;
            }
            foreach ($names as $name) {
                $this->grantRole($roleIds[$role], $permIds[$name], $now);
            }
        }

        // Per-user overrides of the old permission carry over (grant or deny).
        $overrides = DB::table('permission_user')->where('permission_id', $source)->get(['user_id', 'is_denied']);
        foreach ($overrides as $o) {
            foreach (self::MIRROR as $name) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $o->user_id,
                    'permission_id' => $permIds[$name],
                    'is_denied' => (bool) $o->is_denied,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::NEW_PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        DB::table('permissions')->where('name', 'manage_task_templates')->update(['label' => 'Manage tasks and campaign oversight']);
        DB::table('permissions')->where('name', 'manage_campaigns')->update(['label' => 'Manage campaigns (create / edit / status)']);
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
};

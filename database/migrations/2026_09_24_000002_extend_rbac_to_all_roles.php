<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends RBAC from staff-only to every role:
 *
 * - permission_user.is_denied: a per-user override can now REVOKE a
 *   permission the user's role grants (e.g. block one contributor's
 *   withdrawals), not only add one.
 * - Seeds all five roles, the full permission catalog and the default
 *   grants. Defaults reproduce exactly what each role could do before its
 *   routes were permission-gated, so deploying this changes no behaviour
 *   until Super Admin edits the matrix.
 *
 * Data is inlined (not read from the Permission model) so this migration
 * stays stable if the catalog changes later.
 */
return new class extends Migration
{
    private const ROLES = [
        'contributor' => 'Contributor',
        'business' => 'Business',
        'moderator' => 'Moderator',
        'admin' => 'Admin',
        'superadmin' => 'Super Admin',
    ];

    private const PERMISSIONS = [
        'review_submissions' => 'Review task submissions (approve / reject)',
        'review_kyc' => 'Review KYC identity documents',
        'handle_disputes' => 'Handle support tickets and disputes',
        'manage_users' => 'Manage users (view, suspend, reactivate)',
        'manage_campaigns' => 'Manage campaigns (create / edit / status)',
        'manage_task_templates' => 'Manage tasks and campaign oversight',
        'process_payouts' => 'Approve / reject withdrawals',
        'view_reports' => 'View reports, audit logs and analytics',
        'manage_settings' => 'Manage platform settings and feature flags',
        'perform_tasks' => 'Start and submit tasks',
        'request_withdrawals' => 'Request wallet withdrawals',
        'use_referrals' => 'Use the referral program',
        'create_campaigns' => 'Create and launch campaigns',
        'fund_campaigns' => 'Fund campaigns (escrow deposits)',
        'manage_business_tasks' => 'Create / edit / delete own tasks',
        'submit_kyc' => 'Submit KYC identity documents',
        'open_support_tickets' => 'Open and reply to support tickets',
    ];

    private const GRANTS = [
        'moderator' => ['review_submissions', 'review_kyc', 'handle_disputes', 'manage_task_templates'],
        'admin' => [
            'review_submissions', 'review_kyc', 'handle_disputes', 'manage_users', 'manage_campaigns',
            'manage_task_templates', 'process_payouts', 'view_reports', 'manage_settings',
        ],
        'contributor' => ['perform_tasks', 'request_withdrawals', 'use_referrals', 'submit_kyc', 'open_support_tickets'],
        'business' => ['create_campaigns', 'fund_campaigns', 'manage_business_tasks', 'submit_kyc', 'open_support_tickets'],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('permission_user', 'is_denied')) {
            Schema::table('permission_user', function (Blueprint $table) {
                $table->boolean('is_denied')->default(false)->after('permission_id');
            });
        }

        $now = now();

        foreach (self::ROLES as $name => $label) {
            DB::table('roles')->updateOrInsert(['name' => $name], ['label' => $label, 'is_system' => true, 'updated_at' => $now]);
            DB::table('roles')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'updated_at' => $now]);
            DB::table('permissions')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permIds = DB::table('permissions')->pluck('id', 'name');

        foreach (self::GRANTS as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleIds[$role],
                    'permission_id' => $permIds[$permission],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // KYC review used to ride on review_submissions: anyone holding that
        // directly keeps KYC access under the new dedicated permission.
        $direct = DB::table('permission_user')
            ->where('permission_id', $permIds['review_submissions'])
            ->pluck('user_id');
        foreach ($direct as $userId) {
            DB::table('permission_user')->insertOrIgnore([
                'user_id' => $userId,
                'permission_id' => $permIds['review_kyc'],
                'is_denied' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Role grants and catalog rows are left in place (harmless without
        // enforcement); only the schema change is reverted.
        if (Schema::hasColumn('permission_user', 'is_denied')) {
            DB::table('permission_user')->where('is_denied', true)->delete();
            Schema::table('permission_user', function (Blueprint $table) {
                $table->dropColumn('is_denied');
            });
        }
    }
};

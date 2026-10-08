<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    /**
     * Canonical permission names assignable by Super Admin.
     */
    public const REVIEW_SUBMISSIONS = 'review_submissions';
    /** Campaign access: list / view, pause / resume, approve. */
    public const MANAGE_CAMPAIGNS = 'manage_campaigns';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const HANDLE_DISPUTES = 'handle_disputes';
    /** Task access: see the staff task list (create / edit / delete are separate). */
    public const MANAGE_TASK_TEMPLATES = 'manage_task_templates';
    public const CREATE_TASKS = 'create_tasks';
    public const EDIT_TASKS = 'edit_tasks';
    public const DELETE_TASKS = 'delete_tasks';
    /** Post a campaign on behalf of a business (funded from that business's wallet). */
    public const POST_CAMPAIGNS = 'post_campaigns';
    /** Create business user accounts from the admin panel. */
    public const CREATE_BUSINESS_USERS = 'create_business_users';
    public const VIEW_REPORTS = 'view_reports';
    public const REVIEW_KYC = 'review_kyc';
    public const PROCESS_PAYOUTS = 'process_payouts';
    /** Edit referral commissions (L1/L2/L3). Super Admin only by default. */
    public const MANAGE_REFERRAL_RULES = 'manage_referral_rules';
    /** Access the Roles & Permissions page. Admin gets it with guards; superadmin is unrestricted. */
    public const MANAGE_ROLES = 'manage_roles';
    /** Edit campaign details (title, copy, instructions, targeting) platform-wide. */
    public const EDIT_CAMPAIGNS = 'edit_campaigns';
    /** Delete campaigns with no contributor activity (escrow is released first). */
    public const DELETE_CAMPAIGNS = 'delete_campaigns';
    /** Create / edit / delete Task Library templates. Super Admin only by default. */
    public const MANAGE_TASK_LIBRARY = 'manage_task_library';

    // One permission per admin sidebar section (split out of the shared
    // view_reports / manage_users / process_payouts / review_kyc /
    // review_submissions grants so Super Admin can switch each page).
    public const MANAGE_BUSINESSES = 'manage_businesses';
    public const REVIEW_SOCIAL_CHANNELS = 'review_social_channels';
    public const PROCESS_DEPOSITS = 'process_deposits';
    public const VIEW_FRAUD = 'view_fraud';
    public const VIEW_REFERRALS = 'view_referrals';
    public const VIEW_DEMO_REQUESTS = 'view_demo_requests';
    public const VIEW_ANALYTICS = 'view_analytics';
    public const VIEW_TRAFFIC = 'view_traffic';
    public const VIEW_SYSTEM_HEALTH = 'view_system_health';
    public const VIEW_AUDIT_LOGS = 'view_audit_logs';
    public const VIEW_WALLETS = 'view_wallets';
    /** Manually credit / debit a wallet (audit-logged, reason required). */
    public const ADJUST_WALLETS = 'adjust_wallets';

    // Contributor capabilities
    public const PERFORM_TASKS = 'perform_tasks';
    public const REQUEST_WITHDRAWALS = 'request_withdrawals';
    public const USE_REFERRALS = 'use_referrals';

    // Business capabilities
    public const CREATE_CAMPAIGNS = 'create_campaigns';
    public const FUND_CAMPAIGNS = 'fund_campaigns';
    public const MANAGE_BUSINESS_TASKS = 'manage_business_tasks';
    /** First-step approve / reject of proofs on the business's own campaigns. */
    public const REVIEW_CAMPAIGN_PROOFS = 'review_campaign_proofs';
    public const EDIT_OWN_CAMPAIGNS = 'edit_own_campaigns';
    public const DELETE_OWN_CAMPAIGNS = 'delete_own_campaigns';

    // Shared (contributor + business)
    public const SUBMIT_KYC = 'submit_kyc';
    public const OPEN_SUPPORT_TICKETS = 'open_support_tickets';
    /** See the Task Library templates (staff and business). */
    public const VIEW_TASK_LIBRARY = 'view_task_library';

    /** Roles whose grants Super Admin can edit (superadmin holds everything). */
    public const EDITABLE_ROLES = ['admin', 'moderator', 'contributor', 'business'];

    /**
     * Full catalog: name => [label, group]. Group is the audience the
     * permission is meant for, used to lay out the Super Admin matrix.
     */
    public static function definitions(): array
    {
        return [
            self::REVIEW_SUBMISSIONS => ['Review task submissions (approve / reject)', 'staff'],
            self::REVIEW_KYC => ['Review KYC identity documents', 'staff'],
            self::HANDLE_DISPUTES => ['Handle support tickets and disputes', 'staff'],
            self::MANAGE_USERS => ['Manage users (view, suspend, reactivate)', 'staff'],
            self::CREATE_BUSINESS_USERS => ['Create business user accounts', 'staff'],
            self::MANAGE_CAMPAIGNS => ['Access campaigns (view, pause / resume, approve)', 'staff'],
            self::POST_CAMPAIGNS => ['Create campaigns for a business', 'staff'],
            self::MANAGE_TASK_TEMPLATES => ['Access tasks (view the task list)', 'staff'],
            self::CREATE_TASKS => ['Create tasks', 'staff'],
            self::EDIT_TASKS => ['Edit tasks (details, pause / resume)', 'staff'],
            self::DELETE_TASKS => ['Delete tasks (no contributor activity only)', 'staff'],
            self::PROCESS_PAYOUTS => ['Approve / reject withdrawals', 'staff'],
            self::VIEW_REPORTS => ['View reports, audit logs and analytics', 'staff'],
            self::MANAGE_SETTINGS => ['Manage platform settings and feature flags', 'staff'],
            self::MANAGE_REFERRAL_RULES => ['Change referral commissions (L1 / L2 / L3)', 'staff'],
            self::EDIT_CAMPAIGNS => ['Edit any campaign\'s details', 'staff'],
            self::DELETE_CAMPAIGNS => ['Delete campaigns (no contributor activity only)', 'staff'],
            self::MANAGE_TASK_LIBRARY => ['Create / edit / delete Task Library templates', 'staff'],
            self::MANAGE_ROLES => ['Manage roles, departments & permissions', 'staff'],
            self::MANAGE_BUSINESSES => ['Businesses page (view, suspend, log in as)', 'staff'],
            self::REVIEW_SOCIAL_CHANNELS => ['Social Channels page (verify linked accounts)', 'staff'],
            self::PROCESS_DEPOSITS => ['Deposits page (approve / reject deposits)', 'staff'],
            self::VIEW_FRAUD => ['Fraud & Risk page', 'staff'],
            self::VIEW_REFERRALS => ['Referrals page', 'staff'],
            self::VIEW_DEMO_REQUESTS => ['Demo Requests page', 'staff'],
            self::VIEW_ANALYTICS => ['Analytics page', 'staff'],
            self::VIEW_TRAFFIC => ['Website Traffic page', 'staff'],
            self::VIEW_SYSTEM_HEALTH => ['System Health page', 'staff'],
            self::VIEW_AUDIT_LOGS => ['Audit Logs page', 'staff'],
            self::VIEW_WALLETS => ['Wallets page (view balances and ledgers)', 'staff'],
            self::ADJUST_WALLETS => ['Add / remove wallet money (manual credit / debit)', 'staff'],

            self::PERFORM_TASKS => ['Start and submit tasks', 'contributor'],
            self::REQUEST_WITHDRAWALS => ['Request wallet withdrawals', 'contributor'],
            self::USE_REFERRALS => ['Use the referral program', 'contributor'],

            self::CREATE_CAMPAIGNS => ['Create and launch campaigns', 'business'],
            self::FUND_CAMPAIGNS => ['Fund campaigns (escrow deposits)', 'business'],
            self::MANAGE_BUSINESS_TASKS => ['Create / edit / delete own tasks', 'business'],
            self::REVIEW_CAMPAIGN_PROOFS => ['Approve / reject proofs on own campaigns', 'business'],
            self::EDIT_OWN_CAMPAIGNS => ['Edit own campaign details', 'business'],
            self::DELETE_OWN_CAMPAIGNS => ['Delete own campaigns (no contributor activity only)', 'business'],

            self::SUBMIT_KYC => ['Submit KYC identity documents', 'account'],
            self::OPEN_SUPPORT_TICKETS => ['Open and reply to support tickets', 'account'],
            self::VIEW_TASK_LIBRARY => ['See the Task Library templates', 'account'],
        ];
    }

    /** name => label (kept for existing callers). */
    public static function catalog(): array
    {
        return array_map(fn (array $def) => $def[0], self::definitions());
    }

    /**
     * Default grants per role. Every capability a role had before
     * permissions were enforced on its routes is granted here, so turning
     * enforcement on changes nothing until Super Admin edits the matrix.
     */
    public static function defaultGrants(): array
    {
        return [
            'moderator' => [
                self::REVIEW_SUBMISSIONS, self::REVIEW_KYC, self::HANDLE_DISPUTES,
                self::MANAGE_TASK_TEMPLATES, self::CREATE_TASKS, self::EDIT_TASKS, self::DELETE_TASKS,
                self::MANAGE_CAMPAIGNS, self::POST_CAMPAIGNS,
                self::REVIEW_SOCIAL_CHANNELS, self::VIEW_FRAUD,
            ],
            'admin' => [
                self::REVIEW_SUBMISSIONS, self::REVIEW_KYC, self::HANDLE_DISPUTES, self::MANAGE_USERS,
                self::CREATE_BUSINESS_USERS,
                self::MANAGE_CAMPAIGNS, self::POST_CAMPAIGNS, self::EDIT_CAMPAIGNS, self::DELETE_CAMPAIGNS,
                self::MANAGE_TASK_TEMPLATES, self::CREATE_TASKS, self::EDIT_TASKS, self::DELETE_TASKS,
                self::PROCESS_PAYOUTS, self::VIEW_REPORTS, self::MANAGE_SETTINGS, self::VIEW_TASK_LIBRARY,
                self::MANAGE_ROLES,
                self::MANAGE_BUSINESSES, self::REVIEW_SOCIAL_CHANNELS, self::PROCESS_DEPOSITS, self::VIEW_FRAUD,
                self::VIEW_REFERRALS, self::VIEW_DEMO_REQUESTS, self::VIEW_ANALYTICS, self::VIEW_TRAFFIC,
                self::VIEW_SYSTEM_HEALTH, self::VIEW_AUDIT_LOGS,
            ],
            'contributor' => [
                self::PERFORM_TASKS, self::REQUEST_WITHDRAWALS, self::USE_REFERRALS,
                self::SUBMIT_KYC, self::OPEN_SUPPORT_TICKETS,
            ],
            'business' => [
                self::CREATE_CAMPAIGNS, self::FUND_CAMPAIGNS, self::MANAGE_BUSINESS_TASKS, self::REVIEW_CAMPAIGN_PROOFS,
                self::EDIT_OWN_CAMPAIGNS, self::DELETE_OWN_CAMPAIGNS,
                self::SUBMIT_KYC, self::OPEN_SUPPORT_TICKETS, self::VIEW_TASK_LIBRARY,
            ],
        ];
    }

    protected $fillable = [
        'name',
        'label',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user')->withTimestamps();
    }
}

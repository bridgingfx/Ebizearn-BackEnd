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
    public const MANAGE_CAMPAIGNS = 'manage_campaigns';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const HANDLE_DISPUTES = 'handle_disputes';
    public const MANAGE_TASK_TEMPLATES = 'manage_task_templates';
    public const VIEW_REPORTS = 'view_reports';
    public const REVIEW_KYC = 'review_kyc';
    public const PROCESS_PAYOUTS = 'process_payouts';
    /** Edit referral commissions (L1/L2/L3). Super Admin only by default. */
    public const MANAGE_REFERRAL_RULES = 'manage_referral_rules';

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

    // Shared (contributor + business)
    public const SUBMIT_KYC = 'submit_kyc';
    public const OPEN_SUPPORT_TICKETS = 'open_support_tickets';

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
            self::MANAGE_CAMPAIGNS => ['Manage campaigns (create / edit / status)', 'staff'],
            self::MANAGE_TASK_TEMPLATES => ['Manage tasks and campaign oversight', 'staff'],
            self::PROCESS_PAYOUTS => ['Approve / reject withdrawals', 'staff'],
            self::VIEW_REPORTS => ['View reports, audit logs and analytics', 'staff'],
            self::MANAGE_SETTINGS => ['Manage platform settings and feature flags', 'staff'],
            self::MANAGE_REFERRAL_RULES => ['Change referral commissions (L1 / L2 / L3)', 'staff'],

            self::PERFORM_TASKS => ['Start and submit tasks', 'contributor'],
            self::REQUEST_WITHDRAWALS => ['Request wallet withdrawals', 'contributor'],
            self::USE_REFERRALS => ['Use the referral program', 'contributor'],

            self::CREATE_CAMPAIGNS => ['Create and launch campaigns', 'business'],
            self::FUND_CAMPAIGNS => ['Fund campaigns (escrow deposits)', 'business'],
            self::MANAGE_BUSINESS_TASKS => ['Create / edit / delete own tasks', 'business'],
            self::REVIEW_CAMPAIGN_PROOFS => ['Approve / reject proofs on own campaigns', 'business'],

            self::SUBMIT_KYC => ['Submit KYC identity documents', 'account'],
            self::OPEN_SUPPORT_TICKETS => ['Open and reply to support tickets', 'account'],
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
                self::REVIEW_SUBMISSIONS, self::REVIEW_KYC, self::HANDLE_DISPUTES, self::MANAGE_TASK_TEMPLATES,
            ],
            'admin' => [
                self::REVIEW_SUBMISSIONS, self::REVIEW_KYC, self::HANDLE_DISPUTES, self::MANAGE_USERS,
                self::MANAGE_CAMPAIGNS, self::MANAGE_TASK_TEMPLATES, self::PROCESS_PAYOUTS,
                self::VIEW_REPORTS, self::MANAGE_SETTINGS,
            ],
            'contributor' => [
                self::PERFORM_TASKS, self::REQUEST_WITHDRAWALS, self::USE_REFERRALS,
                self::SUBMIT_KYC, self::OPEN_SUPPORT_TICKETS,
            ],
            'business' => [
                self::CREATE_CAMPAIGNS, self::FUND_CAMPAIGNS, self::MANAGE_BUSINESS_TASKS, self::REVIEW_CAMPAIGN_PROOFS,
                self::SUBMIT_KYC, self::OPEN_SUPPORT_TICKETS,
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

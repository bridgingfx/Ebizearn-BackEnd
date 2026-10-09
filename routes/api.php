<?php

use App\Http\Controllers\Api\V1\AdminEmailCampaignController;
use App\Http\Controllers\Api\V1\AdminEmailController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AdminReferralController;
use App\Http\Controllers\Api\V1\AdminSystemController;
use App\Http\Controllers\Api\V1\AdminTaskController;
use App\Http\Controllers\Api\V1\AdminVerificationController;
use App\Http\Controllers\Api\V1\TrafficAnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthProviderSettingsController;
use App\Http\Controllers\Api\V1\AiSettingsController;
use App\Http\Controllers\Api\V1\BusinessCampaignController;
use App\Http\Controllers\Api\V1\BusinessTaskController;
use App\Http\Controllers\Api\V1\BusinessTeamController;
use App\Http\Controllers\Api\V1\CampaignWizardController;
use App\Http\Controllers\Api\V1\CampaignContentImageController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DemoRequestController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\Ops\OpsAdminController;
use App\Http\Controllers\Api\V1\Ops\OpsPermissionController;
use App\Http\Controllers\Api\V1\Ops\OpsDepartmentController;
use App\Http\Controllers\Api\V1\Ops\OpsSettingsController;
use App\Http\Controllers\Api\V1\Ops\OpsTaskTypeController;
use App\Http\Controllers\Api\V1\Ops\OpsDropdownController;
use App\Http\Controllers\Api\V1\Ops\OpsSocialPlatformController;
use App\Http\Controllers\Api\V1\Ops\OpsWalletController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\SocialChannelController;
use App\Http\Controllers\Api\V1\StaffCampaignController;
use App\Http\Controllers\Api\V1\StaffKycController;
use App\Http\Controllers\Api\V1\StaffNotificationController;
use App\Http\Controllers\Api\V1\StaffCountryChangeController;
use App\Http\Controllers\Api\V1\RankTierController;
use App\Http\Controllers\Api\V1\KycProviderController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\SocialPlatformController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskTemplateController;
use App\Http\Controllers\Api\V1\TaskTypeController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 1. Public Configuration & Metadata
    Route::get('/config/brand', [ConfigController::class, 'brandConfig']);
    // Anonymous page-view tracking for the traffic analytics dashboard.
    Route::post('/track/page-view', [TrafficAnalyticsController::class, 'track'])->middleware('throttle:120,1');
    // Which social sign-in buttons the login / register pages show.
    Route::get('/config/auth-providers', [AuthProviderSettingsController::class, 'publicConfig']);
    // Which "Connect with …" buttons the Connected Social Accounts tab shows.
    Route::get('/config/social-connect', [SocialConnectController::class, 'publicConfig']);
    Route::get('/task-categories', [ConfigController::class, 'categories']);

    // Phase 4 (public): task-type catalog with proof contracts and
    // enforceable reward bands.
    Route::get('/task-types', [TaskTypeController::class, 'index']);
    // Public: active wizard presets (what a Task Library template pre-fills).
    Route::get('/wizard-presets', [OpsDropdownController::class, 'publicPresets']);

    // Public: social platforms Super Admin configured (additive 2026-10-07).
    Route::get('/platforms', [SocialPlatformController::class, 'index']);

    // Public: one-click unsubscribe link inside marketing campaign emails (signed URL).
    Route::get('/email/unsubscribe/{user}', [AdminEmailCampaignController::class, 'unsubscribe'])
        ->whereNumber('user')
        ->middleware(['signed:relative', 'throttle:30,1'])
        ->name('email.unsubscribe');

    // Public: Sumsub verification webhook. Authenticity comes from the
    // HMAC signature, never from obscurity (additive 2026-10-07).
    Route::post('/webhooks/sumsub', [KycProviderController::class, 'webhook'])
        ->middleware('throttle:60,1')
        ->name('webhooks.sumsub');

    // Public: Stripe payment webhook. Authenticity comes from the Stripe
    // signature, never from obscurity (additive 2026-10-07).
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
        ->middleware('throttle:120,1')
        ->name('webhooks.stripe');

    // 2. Public Authentication
    Route::prefix('auth')->group(function () {
        // Round 2: registration throttled (5/min per IP) + strong password.
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        // Round 2: social sign-in (Google/Apple ID-token, server-side verified).
        Route::post('/social/{provider}', [AuthController::class, 'socialLogin'])
            ->whereIn('provider', ['google', 'apple'])
            ->middleware('throttle:social-login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
        // Round 2: email verification (public — the token is the credential).
        Route::post('/email/verify', [AuthController::class, 'verifyEmail']);
        // Signup hardening: 6-digit email OTP send / verify. Replaces the
        // token-link flow for new email signups (the legacy flow above is
        // kept for pre-existing accounts).
        Route::post('/otp/send', [OtpController::class, 'send'])->middleware('throttle:otp-send');
        Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify');
    });

    // 3. Public Marketplace Preview
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);

    // Public demo request form — rate-limited, stored as real DB rows.
    Route::post('/demo-requests', [DemoRequestController::class, 'store'])
        ->middleware('throttle:demo-requests');

    // 4. Protected Routes
    Route::middleware('auth:sanctum')->group(function () {

        // Auth management
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        // Round 2: verification-email resend (authenticated, 3/min).
        Route::post('/auth/email/resend', [AuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:email-resend');

        // Profile
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:5,1');
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar']);
        // Residence-country change: requested via PUT /profile, approved by staff.
        Route::get('/profile/country-change', [ProfileController::class, 'countryChange']);
        Route::delete('/profile/country-change', [ProfileController::class, 'cancelCountryChange']);
        // KYC: submit identity documents (private disk, reviewed via /staff/kyc).
        Route::post('/profile/kyc', [ProfileController::class, 'submitKyc'])
            ->middleware(['role:contributor,business', 'permission:submit_kyc', 'throttle:10,1']);
        // KYC via Sumsub: the user mints their own WebSDK token when staff
        // set their kyc_method to 'sumsub' (additive 2026-10-07).
        Route::get('/kyc/sumsub/token', [KycProviderController::class, 'userToken'])
            ->middleware(['role:contributor,business', 'permission:submit_kyc', 'throttle:10,1']);
        // Saved USDT payout details (address + network) for manual payouts.
        Route::get('/profile/usdt-payout', [ProfileController::class, 'getUsdtPayout']);
        Route::put('/profile/usdt-payout', [ProfileController::class, 'updateUsdtPayout']);

        // In-app support tickets (own tickets only). Reading history is always
        // allowed; opening/replying needs open_support_tickets.
        Route::middleware('role:contributor,business')->prefix('support/tickets')->group(function () {
            Route::get('/', [SupportTicketController::class, 'index']);
            Route::post('/', [SupportTicketController::class, 'store'])->middleware(['permission:open_support_tickets', 'throttle:10,1']);
            Route::get('/{uuid}', [SupportTicketController::class, 'show']);
            Route::post('/{uuid}/messages', [SupportTicketController::class, 'reply'])->middleware(['permission:open_support_tickets', 'throttle:30,1']);
            Route::get('/{uuid}/messages/{messageId}/attachments/{index}', [SupportTicketController::class, 'attachment'])
                ->whereNumber(['messageId', 'index']);
        });

        // Contributor Endpoints
        Route::middleware('role:contributor')->prefix('contributor')->group(function () {
            // Round 2: dashboard data is gated on email verification.
            Route::get('/dashboard', [TaskController::class, 'contributorDashboard'])->middleware('email.verified');
            Route::get('/my-tasks', [TaskController::class, 'myTasks']);

            // Social channels: manual bio-code flow…
            Route::get('/social-channels', [SocialChannelController::class, 'index']);
            Route::post('/social-channels', [SocialChannelController::class, 'store'])->middleware('throttle:20,1');
            Route::post('/social-channels/{id}/submit', [SocialChannelController::class, 'submit'])->whereNumber('id')->middleware('throttle:20,1');
            Route::delete('/social-channels/{id}', [SocialChannelController::class, 'destroy'])->whereNumber('id');
            // …and "Connect with …" OAuth (the robo verifies these automatically).
            Route::get('/social-connect/{platform}/redirect', [SocialConnectController::class, 'redirect'])
                ->whereIn('platform', ['tiktok', 'x', 'facebook', 'google'])->middleware('throttle:20,1');

            // Phase 8: affiliate endpoints (real ledger-backed data only)
            Route::middleware('permission:use_referrals')->group(function () {
                Route::get('/referrals', [ReferralController::class, 'index']);
                Route::get('/referrals/tree', [ReferralController::class, 'tree']);
                Route::get('/referrals/earnings', [ReferralController::class, 'earnings']);
            });
        });

        // Contributor Task Operations (Round 2: task write paths gated on
        // email verification).
        Route::middleware(['role:contributor', 'email.verified', 'permission:perform_tasks'])->group(function () {
            Route::post('/tasks/{id}/start', [TaskController::class, 'start']);
            Route::post('/tasks/{id}/submit', [TaskController::class, 'submit']);
            // The post text this contributor copies (own AI version in auto mode).
            Route::get('/tasks/{id}/content', [TaskController::class, 'content'])->middleware('throttle:30,1');
        });

        // Contributor Wallet Operations (Round 2: wallet actions gated on
        // email verification).
        Route::middleware(['role:contributor', 'email.verified'])->prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            // Phase 7: breakdown computed from the real ledger + submissions
            Route::get('/breakdown', [WalletController::class, 'breakdown']);
            Route::post('/withdraw', [WalletController::class, 'withdraw'])->middleware('permission:request_withdrawals');
        });

        // Business Endpoints
        Route::middleware('role:business')->prefix('business')->group(function () {
            Route::get('/dashboard', [BusinessCampaignController::class, 'dashboard']);
            // Wallet deposits: automatic (Stripe) or manual methods configured by
            // Super Admin; manual ones are credited after staff approval.
            Route::get('/deposit-methods', [DepositController::class, 'methods'])->middleware('permission:view_billing');
            Route::get('/deposits', [DepositController::class, 'index'])->middleware('permission:view_billing');
            Route::post('/deposits', [DepositController::class, 'store'])->middleware(['permission:view_billing', 'throttle:10,1']);
            // Automatic card payment via Stripe Checkout — the wallet is
            // credited by the webhook, no staff approval needed.
            Route::post('/deposits/stripe-session', [DepositController::class, 'stripeSession'])->middleware(['permission:view_billing', 'throttle:10,1']);
            Route::get('/campaigns', [BusinessCampaignController::class, 'index'])->middleware('permission:view_own_campaigns');
            // Round 2: campaign creation + funding gated on verification.
            Route::post('/campaigns', [BusinessCampaignController::class, 'store'])->middleware(['email.verified', 'permission:create_campaigns']);
            Route::post('/campaigns/generate-content', [BusinessCampaignController::class, 'generateContent'])->middleware('throttle:20,1');
            Route::get('/campaigns/{id}', [BusinessCampaignController::class, 'show'])->middleware('permission:view_own_campaigns');
            Route::patch('/campaigns/{id}/status', [BusinessCampaignController::class, 'updateStatus']);
            Route::patch('/campaigns/{id}', [BusinessCampaignController::class, 'update'])->middleware('permission:edit_own_campaigns');
            Route::delete('/campaigns/{id}', [BusinessCampaignController::class, 'destroy'])->middleware('permission:delete_own_campaigns');
            // Task Library templates Super Admin made visible to businesses.
            Route::get('/task-templates', [TaskTemplateController::class, 'index'])->middleware('permission:view_task_library');
            Route::post('/campaigns/{id}/fund', [BusinessCampaignController::class, 'fund'])->middleware(['email.verified', 'permission:fund_campaigns']);
            Route::post('/campaigns/{id}/logo', [BusinessCampaignController::class, 'uploadLogo']);
            // Image contributors post with the post text.
            Route::post('/campaigns/{id}/content-image', [CampaignContentImageController::class, 'businessUpload'])->middleware('throttle:20,1');
            Route::delete('/campaigns/{id}/content-image', [CampaignContentImageController::class, 'businessRemove']);
            Route::get('/submissions', [BusinessCampaignController::class, 'submissions'])->middleware('permission:review_campaign_proofs');
            // Two-step review: the business recommends, staff confirm and release payment.
            Route::post('/submissions/{id}/decision', [BusinessCampaignController::class, 'reviewSubmission'])
                ->middleware(['permission:review_campaign_proofs', 'throttle:60,1']);

            // ==============================================================
            // Phase 9 — CAMPAIGN WIZARD (Worker C): preview -> draft -> launch.
            // Tenant-scoped: a business touches only its own campaigns.
            // Round 2: draft/launch gated on email verification.
            // ==============================================================
            Route::post('/campaigns/wizard/preview', [CampaignWizardController::class, 'preview']);
            Route::post('/campaigns/wizard/draft', [CampaignWizardController::class, 'draft'])->middleware(['email.verified', 'permission:create_campaigns']);
            Route::patch('/campaigns/wizard/draft/{id}', [CampaignWizardController::class, 'updateDraft'])->middleware(['email.verified', 'permission:create_campaigns']);
            Route::post('/campaigns/{id}/launch', [CampaignWizardController::class, 'launch'])->middleware(['email.verified', 'permission:create_campaigns']);

            // ==============================================================
            // Phase 4/13 — BUSINESS TASK CRUD (Worker C): strictly own-tenant
            // via TaskPolicy; rewards band-validated (Phase 12).
            // Round 2: writes gated on email verification.
            // ==============================================================
            Route::get('/tasks', [BusinessTaskController::class, 'index']);
            Route::post('/tasks', [BusinessTaskController::class, 'store'])->middleware(['email.verified', 'permission:manage_business_tasks']);
            Route::get('/tasks/{id}', [BusinessTaskController::class, 'show']);
            Route::patch('/tasks/{id}', [BusinessTaskController::class, 'update'])->middleware(['email.verified', 'permission:manage_business_tasks']);
            Route::delete('/tasks/{id}', [BusinessTaskController::class, 'destroy'])->middleware(['email.verified', 'permission:manage_business_tasks']);

            // Phase 9 — basic business analytics from REAL aggregates.
            Route::get('/analytics', [BusinessTaskController::class, 'analytics'])->middleware('permission:view_business_analytics');

            // Team Access: the owner adds team members who work on this
            // business with the sections the owner picks for them.
            Route::middleware('permission:manage_team')->prefix('team')->group(function () {
                Route::get('/', [BusinessTeamController::class, 'index']);
                Route::post('/', [BusinessTeamController::class, 'store'])->middleware('throttle:20,1');
                Route::put('/{id}/permissions', [BusinessTeamController::class, 'updatePermissions'])->whereNumber('id');
                Route::patch('/{id}/status', [BusinessTeamController::class, 'updateStatus'])->whereNumber('id');
                Route::delete('/{id}', [BusinessTeamController::class, 'destroy'])->whereNumber('id');
            });
        });

        // Super Admin only: which deposit methods businesses can use, and their details.
        Route::middleware('role:superadmin')->prefix('admin/deposit-methods')->group(function () {
            Route::get('/', [DepositController::class, 'adminMethods']);
            Route::put('/{key}', [DepositController::class, 'updateMethod'])->whereIn('key', ['card', 'bank', 'email', 'crypto']);
        });

        // Super Admin only: Google / Apple sign-in settings.
        Route::middleware('role:superadmin')->prefix('admin/auth-providers')->group(function () {
            Route::get('/', [AuthProviderSettingsController::class, 'show']);
            Route::put('/', [AuthProviderSettingsController::class, 'update']);
        });

        // Super Admin only: "Connect with …" OAuth apps for social channels.
        Route::middleware('role:superadmin')->prefix('admin/social-connect')->group(function () {
            Route::get('/', [SocialConnectController::class, 'adminShow']);
            Route::put('/', [SocialConnectController::class, 'adminUpdate']);
        });

        // Super Admin only: AI content generator (provider, encrypted API key, model).
        Route::middleware('role:superadmin')->prefix('admin/ai-settings')->group(function () {
            Route::get('/', [AiSettingsController::class, 'show']);
            Route::put('/', [AiSettingsController::class, 'update']);
            Route::post('/test', [AiSettingsController::class, 'test'])->middleware('throttle:10,1');
            Route::post('/try', [AiSettingsController::class, 'tryGenerate'])->middleware('throttle:10,1');
            Route::delete('/key', [AiSettingsController::class, 'removeKey']);
        });

        // Super Admin only: contributor rank tiers (promotion thresholds + bonus %)
        Route::middleware('role:superadmin')->prefix('admin/rank-tiers')->group(function () {
            Route::get('/', [RankTierController::class, 'index']);
            Route::patch('/{id}', [RankTierController::class, 'update']);
        });

        // Super Admin only: email providers, templates, logs
        Route::middleware('role:superadmin')->prefix('admin/email')->group(function () {
            Route::get('/providers', [AdminEmailController::class, 'providers']);
            Route::post('/providers', [AdminEmailController::class, 'storeProvider']);
            Route::put('/providers/{id}', [AdminEmailController::class, 'updateProvider']);
            Route::delete('/providers/{id}', [AdminEmailController::class, 'destroyProvider']);
            Route::post('/providers/{id}/active', [AdminEmailController::class, 'setActive']);
            Route::post('/providers/{id}/test', [AdminEmailController::class, 'testProvider']);
            Route::get('/templates', [AdminEmailController::class, 'templates']);
            Route::post('/templates', [AdminEmailController::class, 'storeTemplate']);
            Route::delete('/templates/{key}', [AdminEmailController::class, 'destroyTemplate']);
            Route::post('/templates/{key}/test', [AdminEmailController::class, 'testTemplate'])->middleware('throttle:20,1');
            Route::post('/assets', [AdminEmailController::class, 'uploadAsset'])->middleware('throttle:30,1');
            Route::put('/templates/{key}', [AdminEmailController::class, 'updateTemplate']);
            Route::post('/templates/{key}/reset', [AdminEmailController::class, 'resetTemplate']);
            Route::get('/logs', [AdminEmailController::class, 'logs']);
            Route::get('/status', [AdminEmailController::class, 'status']);
            Route::post('/apply', [AdminEmailController::class, 'apply']);

            // Marketing campaigns, sent in batches through the active provider.
            Route::get('/campaigns', [AdminEmailCampaignController::class, 'index']);
            Route::get('/campaigns/audiences', [AdminEmailCampaignController::class, 'audienceCounts']);
            Route::post('/campaigns', [AdminEmailCampaignController::class, 'store']);
            Route::put('/campaigns/{id}', [AdminEmailCampaignController::class, 'update']);
            Route::delete('/campaigns/{id}', [AdminEmailCampaignController::class, 'destroy']);
            Route::post('/campaigns/{id}/test', [AdminEmailCampaignController::class, 'test']);
            Route::post('/campaigns/{id}/send', [AdminEmailCampaignController::class, 'send']);
            Route::post('/campaigns/{id}/cancel', [AdminEmailCampaignController::class, 'cancel']);
        });

        // Super Admin only: payment gateways and payment attempt logs
        Route::middleware('role:superadmin')->prefix('admin/payments')->group(function () {
            Route::get('/gateways', [AdminPaymentController::class, 'gateways']);
            Route::post('/gateways', [AdminPaymentController::class, 'storeGateway']);
            Route::put('/gateways/{id}', [AdminPaymentController::class, 'updateGateway']);
            Route::delete('/gateways/{id}', [AdminPaymentController::class, 'destroyGateway']);
            Route::post('/gateways/{id}/active', [AdminPaymentController::class, 'setActive']);
            Route::post('/gateways/{id}/test', [AdminPaymentController::class, 'testGateway']);
            Route::get('/logs', [AdminPaymentController::class, 'logs']);
        });

        // Staff panel endpoints. Each area is gated on its sidebar-section
        // permission, so Super Admin (or an admin, within their own access)
        // decides per role / per account — moderators included.
        Route::middleware('role:admin,superadmin,moderator')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminVerificationController::class, 'dashboard'])->middleware('role:admin,superadmin');
            // One permission per sidebar section (Roles & Permissions).
            Route::get('/health', [AdminSystemController::class, 'health'])->middleware('permission:view_system_health');
            Route::middleware('permission:view_traffic')->group(function () {
                Route::get('/traffic', [TrafficAnalyticsController::class, 'overview']);
                Route::get('/traffic/sessions/{sessionId}', [TrafficAnalyticsController::class, 'sessionDetail']);
            });

            Route::middleware('permission:review_submissions')->group(function () {
                Route::get('/verification-queue', [AdminVerificationController::class, 'verificationQueue']);
                Route::get('/submissions/{id}', [AdminVerificationController::class, 'submissionDetail']);
                Route::post('/submissions/{id}/decision', [AdminVerificationController::class, 'recordDecision']);
            });
            Route::get('/fraud-alerts', [AdminVerificationController::class, 'fraudAlerts'])->middleware('permission:view_fraud');

            Route::middleware('permission:process_payouts')->group(function () {
                Route::get('/payouts', [AdminVerificationController::class, 'payouts']);
                Route::post('/payouts/{id}/process', [AdminVerificationController::class, 'processPayout']);
                // Record the on-chain tx hash after a USDT payout is sent
                // manually from the company wallet.
                Route::post('/payouts/{id}/tx-hash', [AdminVerificationController::class, 'recordTxHash']);
            });

            // Business deposits: confirm the money arrived, then credit the wallet.
            Route::middleware('permission:process_deposits')->group(function () {
                Route::get('/deposits', [DepositController::class, 'staffIndex']);
                Route::get('/deposits/{id}/proof', [DepositController::class, 'proof'])->whereNumber('id');
                Route::post('/deposits/{id}/decision', [DepositController::class, 'decision'])->whereNumber('id');
            });

            Route::get('/referrals/overview', [AdminReferralController::class, 'overview'])->middleware('permission:view_referrals');
            // Audit logs: their own page, and also shown inside Reports.
            Route::get('/audit-logs', [AdminSystemController::class, 'auditLogs'])->middleware('permission.any:view_audit_logs,view_reports');

            // Notification bell: all platform activity (Admin / Super Admin).
            Route::middleware('role:admin,superadmin')->prefix('notifications')->group(function () {
                Route::get('/', [StaffNotificationController::class, 'index']);
                Route::get('/unread-count', [StaffNotificationController::class, 'unread']);
                Route::post('/seen', [StaffNotificationController::class, 'markSeen']);
            });
            // Demo-request triage (public submissions, admin read only)
            Route::get('/demo-requests', [DemoRequestController::class, 'index'])->middleware('permission:view_demo_requests');

            // Referral commissions: viewing needs the Referrals page (or the
            // right to change them); changing L1/L2/L3 needs
            // manage_referral_rules (Super Admin, or an admin they grant it to).
            Route::get('/referral-rules', [AdminReferralController::class, 'rules'])->middleware('permission.any:view_referrals,manage_referral_rules');
            Route::patch('/referral-rules', [AdminReferralController::class, 'updateRules'])
                ->middleware('permission:manage_referral_rules');

            Route::middleware('permission:manage_settings')->group(function () {
                Route::get('/feature-flags', [AdminSystemController::class, 'featureFlags']);
                Route::patch('/feature-flags/{key}', [AdminSystemController::class, 'updateFeatureFlag']);
                Route::get('/system-settings', [AdminSystemController::class, 'systemSettings']);
                Route::patch('/system-settings', [AdminSystemController::class, 'updateSystemSetting']);
            });

            // Create a business user account (ready to sign in, business role only).
            Route::post('/businesses', [AdminSystemController::class, 'createBusinessUser'])
                ->middleware(['permission:create_business_users', 'throttle:30,1']);

            // The user list feeds Users & KYC, Businesses and Wallets; the
            // controller narrows it to businesses for a Businesses-only admin.
            Route::get('/users', [AdminSystemController::class, 'users'])
                ->middleware('permission.any:manage_users,manage_businesses,view_wallets,manage_roles');
            // Business accounts can be managed from the Businesses page;
            // everyone else needs manage_users (enforced per target user).
            Route::middleware('permission.any:manage_users,manage_businesses')->group(function () {
                Route::get('/users/{id}', [AdminSystemController::class, 'showUser'])->whereNumber('id');
                Route::patch('/users/{id}', [AdminSystemController::class, 'updateUser'])->whereNumber('id');
                Route::post('/users/{id}/impersonate', [AdminSystemController::class, 'impersonate'])->whereNumber('id');
                Route::post('/impersonation/stop', [AdminSystemController::class, 'stopImpersonation']);
                Route::patch('/users/{id}/status', [AdminSystemController::class, 'updateUserStatus']);
                // Contributor level: set by hand and lock, or follow completed tasks.
                Route::patch('/users/{id}/level', [AdminSystemController::class, 'updateContributorLevel'])->whereNumber('id');
            });
        });

        // ==================================================================
        // Phase 4/6 — STAFF TASK MANAGEMENT + MODERATOR VERIFICATION (Worker C)
        // Moderators, admins and super-admins share these via the granular
        // permission gate (EnsurePermission): review_submissions for the
        // queue/decisions, manage_task_templates for task CRUD.
        // ==================================================================
        Route::middleware(['role:moderator,admin,superadmin', 'permission:review_submissions'])->prefix('moderator')->group(function () {
            Route::get('/verification-queue', [AdminVerificationController::class, 'verificationQueue']);
            Route::get('/submissions/{id}', [AdminVerificationController::class, 'submissionDetail']);
            Route::post('/submissions/{id}/decision', [AdminVerificationController::class, 'recordDecision']);
            Route::get('/fraud-alerts', [AdminVerificationController::class, 'fraudAlerts']);
        });

        // Support desk: the shared ticket queue (handle_disputes).
        Route::middleware(['role:moderator,admin,superadmin', 'permission:handle_disputes'])->prefix('staff/support')->group(function () {
            Route::get('/tickets', [SupportTicketController::class, 'staffIndex']);
            Route::get('/tickets/{uuid}', [SupportTicketController::class, 'staffShow']);
            Route::post('/tickets/{uuid}/messages', [SupportTicketController::class, 'staffReply']);
            Route::patch('/tickets/{uuid}', [SupportTicketController::class, 'staffUpdate']);
            Route::get('/tickets/{uuid}/messages/{messageId}/attachments/{index}', [SupportTicketController::class, 'staffAttachment'])
                ->whereNumber(['messageId', 'index']);
        });

        // Residence-country change requests (review_kyc): approve resets KYC
        // for the new country.
        Route::middleware(['role:moderator,admin,superadmin', 'permission:review_kyc'])->prefix('staff/country-changes')->group(function () {
            Route::get('/', [StaffCountryChangeController::class, 'index']);
            Route::post('/{id}/decision', [StaffCountryChangeController::class, 'decision'])->whereNumber('id');
        });

        // KYC review queue (review_kyc).
        Route::middleware(['role:moderator,admin,superadmin', 'permission:review_kyc'])->prefix('staff/kyc')->group(function () {
            Route::get('/', [StaffKycController::class, 'index']);
            Route::get('/{userId}/documents/{side}', [StaffKycController::class, 'document'])
                ->whereIn('side', ['front', 'back', 'selfie']);
            Route::post('/{userId}/decision', [StaffKycController::class, 'decision']);

            // KYC provider selection: manual vs Sumsub (additive 2026-10-07).
            // Admin and superadmin choose per user; Sumsub failure falls back
            // to manual. Existing manual flow above is untouched.
            Route::get('/provider-status', [KycProviderController::class, 'providerStatus']);
            Route::post('/{userId}/method', [KycProviderController::class, 'setMethod'])->whereNumber('userId');
            Route::post('/{userId}/sumsub/token', [KycProviderController::class, 'staffToken'])->whereNumber('userId');
        });

        // Social channel review queue (same reviewers as KYC).
        Route::middleware(['role:moderator,admin,superadmin', 'permission:review_social_channels'])->prefix('staff/social-channels')->group(function () {
            Route::get('/', [SocialChannelController::class, 'staffIndex']);
            Route::post('/{id}/decision', [SocialChannelController::class, 'decision'])->whereNumber('id');
        });

        // Staff tasks: manage_task_templates opens the task list; create /
        // edit (incl. pause/resume) / delete each need their own permission,
        // so Super Admin can switch them per role or per user.
        Route::middleware(['role:moderator,admin,superadmin', 'permission:manage_task_templates'])->prefix('staff/tasks')->group(function () {
            Route::get('/', [AdminTaskController::class, 'index']);
            // Campaigns a new task can be added to (for the create form).
            Route::get('/campaign-options', [AdminTaskController::class, 'campaignOptions'])->middleware('permission:create_tasks');
            Route::post('/', [AdminTaskController::class, 'store'])->middleware('permission:create_tasks');
            Route::patch('/{id}', [AdminTaskController::class, 'update'])->middleware('permission:edit_tasks');
            Route::delete('/{id}', [AdminTaskController::class, 'destroy'])->middleware('permission:delete_tasks');
        });

        // Phase 9 — STAFF CAMPAIGN MANAGEMENT: manage_campaigns opens the
        // cross-tenant list (view, pause/resume/cancel with escrow release,
        // approval). Create (post_campaigns), edit (edit_campaigns) and safe
        // delete (delete_campaigns — refused once contributors worked on it;
        // escrow released first) are gated separately.
        Route::middleware(['role:moderator,admin,superadmin', 'permission:manage_campaigns'])->prefix('staff/campaigns')->group(function () {
            Route::get('/', [StaffCampaignController::class, 'index']);
            // Businesses a campaign can be posted for (for the create form).
            Route::get('/business-options', [StaffCampaignController::class, 'businessOptions'])->middleware('permission:post_campaigns');
            // Staff-created campaign on behalf of a business (business_id
            // required). Same creation pipeline as the business portal —
            // reward bands, P0 funding gate against the business wallet,
            // escrow hold + task pool, parked pending_review.
            Route::post('/', [StaffCampaignController::class, 'store'])->middleware('permission:post_campaigns');
            Route::get('/{id}', [StaffCampaignController::class, 'show']);
            Route::patch('/{id}/status', [StaffCampaignController::class, 'updateStatus']);
            // Post content contributors copy: staff override + approval.
            Route::patch('/{id}/content', [StaffCampaignController::class, 'updateContent'])->middleware('permission:edit_campaigns');
            Route::post('/{id}/content-image', [CampaignContentImageController::class, 'staffUpload'])->middleware('permission:edit_campaigns');
            Route::delete('/{id}/content-image', [CampaignContentImageController::class, 'staffRemove'])->middleware('permission:edit_campaigns');
            Route::post('/{id}/content/decision', [StaffCampaignController::class, 'contentDecision']);
            Route::patch('/{id}', [StaffCampaignController::class, 'update'])->middleware('permission:edit_campaigns');
            Route::delete('/{id}', [StaffCampaignController::class, 'destroy'])->middleware('permission:delete_campaigns');
        });

        // Task Library: staff read (visibility per template), and template
        // CRUD for manage_task_library (Super Admin, or an admin granted it).
        Route::middleware(['role:moderator,admin,superadmin'])->prefix('staff/task-templates')->group(function () {
            Route::get('/', [TaskTemplateController::class, 'index'])->middleware('permission:view_task_library');
            Route::middleware('permission:manage_task_library')->group(function () {
                Route::post('/', [TaskTemplateController::class, 'store']);
                Route::patch('/{id}', [TaskTemplateController::class, 'update'])->whereNumber('id');
                Route::delete('/{id}', [TaskTemplateController::class, 'destroy'])->whereNumber('id');
            });
        });

        // ==================================================================
        // 5. SUPER ADMIN OPS (Phase 2) — hidden /ops prefix, NOT referenced by
        //    any public UI route. Super Admin only. Staff creation, permission
        //    assignment, countries, task categories, withdrawal rules, fraud
        //    rules, platform settings, audit log read.
        // ==================================================================
        Route::middleware(['role:superadmin', 'throttle:ops'])->prefix('ops')->group(function () {
            // Staff (admin/moderator) accounts — superadmin itself is created
            // only via `php artisan superadmin:create`
            Route::get('/admins', [OpsAdminController::class, 'index']);
            Route::post('/admins', [OpsAdminController::class, 'store'])->middleware('throttle:15,1');
            // Assign contributors / businesses / moderators to a staff
            // account; it then only sees and manages those users.
            Route::get('/staff/{id}/assignments', [\App\Http\Controllers\Api\V1\Ops\OpsAssignmentController::class, 'show'])->whereNumber('id');
            Route::put('/staff/{id}/assignments', [\App\Http\Controllers\Api\V1\Ops\OpsAssignmentController::class, 'update'])->whereNumber('id');
            Route::patch('/admins/{id}/permissions', [OpsAdminController::class, 'updatePermissions']);
            Route::get('/permissions', [OpsAdminController::class, 'permissions']);

            // Permission management for every role + per-user overrides.
            // Shared: superadmin (full) and admin with the manage_roles
            // permission (guarded — cannot touch the admin role or admins).
            // (The manage_roles routes shared with admins live in the
            // admin+superadmin ops group below — nesting them here made the
            // outer role:superadmin block every admin.)
            Route::middleware('role:superadmin')->group(function () {
                Route::post('/departments', [OpsDepartmentController::class, 'store']);
                Route::patch('/departments/{id}', [OpsDepartmentController::class, 'update'])->whereNumber('id');
                Route::delete('/departments/{id}', [OpsDepartmentController::class, 'destroy'])->whereNumber('id');
            });

            // Countries
            Route::get('/countries', [OpsSettingsController::class, 'countries']);
            Route::post('/countries', [OpsSettingsController::class, 'storeCountry']);
            Route::patch('/countries/{code}', [OpsSettingsController::class, 'updateCountry']);

            // Task categories
            Route::get('/task-categories', [OpsSettingsController::class, 'taskCategories']);
            Route::post('/task-categories', [OpsSettingsController::class, 'storeTaskCategory']);
            Route::patch('/task-categories/{id}', [OpsSettingsController::class, 'updateTaskCategory']);

            // Withdrawal rules (selectable $10/$25/$50/$100 minimum)
            Route::get('/withdrawal-rules', [OpsSettingsController::class, 'withdrawalRules']);
            Route::post('/withdrawal-rules', [OpsSettingsController::class, 'storeWithdrawalRule']);
            Route::post('/withdrawal-rules/{id}/activate', [OpsSettingsController::class, 'activateWithdrawalRule']);

            // Platform settings + fraud rules (group=fraud)
            Route::get('/platform-settings', [OpsSettingsController::class, 'platformSettings']);
            Route::patch('/platform-settings', [OpsSettingsController::class, 'updatePlatformSettings']);

            // Phase 4/12 (ops, Worker C): task types — bands, allowed flag,
            // proof contracts, retention, fraud rules. Every change audited.
            Route::get('/task-types', [OpsTaskTypeController::class, 'index']);
            Route::patch('/task-types/{key}', [OpsTaskTypeController::class, 'update']);
            Route::post('/task-types/seed', [OpsTaskTypeController::class, 'seed']);
            // Task Library → Dropdown lists: add / delete options.
            Route::post('/task-types', [OpsDropdownController::class, 'storeTaskType']);
            Route::delete('/task-types/{key}', [OpsDropdownController::class, 'destroyTaskType']);
            Route::delete('/task-categories/{id}', [OpsDropdownController::class, 'destroyCategory'])->whereNumber('id');
            Route::get('/wizard-presets', [OpsDropdownController::class, 'presets']);
            Route::post('/wizard-presets', [OpsDropdownController::class, 'storePreset']);
            Route::patch('/wizard-presets/{id}', [OpsDropdownController::class, 'updatePreset'])->whereNumber('id');
            Route::delete('/wizard-presets/{id}', [OpsDropdownController::class, 'destroyPreset'])->whereNumber('id');
            // Sumsub KYC credentials (DB-backed, never env). Superadmin only.
            Route::post('/kyc/sumsub/credentials', [KycProviderController::class, 'saveCredentials']);
            // Social platforms: Super Admin adds a network (name + SVG/PNG
            // logo) and it appears in every picker (additive 2026-10-07).
            Route::get('/platforms', [OpsSocialPlatformController::class, 'index']);
            Route::post('/platforms', [OpsSocialPlatformController::class, 'store']);
            Route::patch('/platforms/{id}', [OpsSocialPlatformController::class, 'update'])->whereNumber('id');
            Route::delete('/platforms/{id}', [OpsSocialPlatformController::class, 'destroy'])->whereNumber('id');
            // Audit log (append-only; read only)
            Route::get('/audit-logs', [OpsSettingsController::class, 'auditLogs']);
        });

        // Wallets: directory, ledger inspection, manual credits ("virtual
        // tokens") and corrective debits. Super Admin grants view_wallets /
        // adjust_wallets to the staff who may use them.
        Route::middleware(['role:admin,superadmin,moderator', 'throttle:ops'])->prefix('ops')->group(function () {
            // Permission management for every role + per-user overrides.
            // Shared: superadmin (full); admin / moderator with manage_roles
            // (guarded — only users below their own role, only their
            // assigned users, only permissions they hold; moderators cannot
            // edit role-wide grants).
            Route::middleware('permission:manage_roles')->group(function () {
                Route::get('/roles', [OpsPermissionController::class, 'roles']);
                Route::put('/roles/{name}/permissions', [OpsPermissionController::class, 'updateRole']);
                Route::get('/users/{id}/permissions', [OpsPermissionController::class, 'user'])->whereNumber('id');
                Route::put('/users/{id}/permissions', [OpsPermissionController::class, 'updateUser'])->whereNumber('id');
                // Departments: everyone with manage_roles can list; only
                // superadmin creates/updates/deletes.
                Route::get('/departments', [OpsDepartmentController::class, 'index']);
                Route::get('/departments/manage', [OpsDepartmentController::class, 'manage']);
            });

            Route::middleware('permission.any:view_wallets,adjust_wallets')->group(function () {
                Route::get('/wallets', [OpsWalletController::class, 'index']);
                Route::get('/wallets/{id}', [OpsWalletController::class, 'show'])->whereNumber('id');
            });
            Route::middleware('permission:adjust_wallets')->group(function () {
                Route::post('/wallets/{id}/credit', [OpsWalletController::class, 'credit'])->whereNumber('id');
                Route::post('/wallets/{id}/debit', [OpsWalletController::class, 'debit'])->whereNumber('id');
            });
        });
    });
});


<?php

use App\Http\Controllers\Api\V1\AdminEmailController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AdminReferralController;
use App\Http\Controllers\Api\V1\AdminSystemController;
use App\Http\Controllers\Api\V1\AdminTaskController;
use App\Http\Controllers\Api\V1\AdminVerificationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessCampaignController;
use App\Http\Controllers\Api\V1\BusinessTaskController;
use App\Http\Controllers\Api\V1\CampaignWizardController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DemoRequestController;
use App\Http\Controllers\Api\V1\Ops\OpsAdminController;
use App\Http\Controllers\Api\V1\Ops\OpsSettingsController;
use App\Http\Controllers\Api\V1\Ops\OpsTaskTypeController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\StaffCampaignController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskTypeController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 1. Public Configuration & Metadata
    Route::get('/config/brand', [ConfigController::class, 'brandConfig']);
    Route::get('/task-categories', [ConfigController::class, 'categories']);

    // Phase 4 (public): task-type catalog with proof contracts and
    // enforceable reward bands.
    Route::get('/task-types', [TaskTypeController::class, 'index']);

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
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar']);

        // Contributor Endpoints
        Route::middleware('role:contributor')->prefix('contributor')->group(function () {
            // Round 2: dashboard data is gated on email verification.
            Route::get('/dashboard', [TaskController::class, 'contributorDashboard'])->middleware('email.verified');
            Route::get('/my-tasks', [TaskController::class, 'myTasks']);

            // Phase 8: affiliate endpoints (real ledger-backed data only)
            Route::get('/referrals', [ReferralController::class, 'index']);
            Route::get('/referrals/tree', [ReferralController::class, 'tree']);
            Route::get('/referrals/earnings', [ReferralController::class, 'earnings']);
        });

        // Contributor Task Operations (Round 2: task write paths gated on
        // email verification).
        Route::middleware(['role:contributor', 'email.verified'])->group(function () {
            Route::post('/tasks/{id}/start', [TaskController::class, 'start']);
            Route::post('/tasks/{id}/submit', [TaskController::class, 'submit']);
        });

        // Contributor Wallet Operations (Round 2: wallet actions gated on
        // email verification).
        Route::middleware(['role:contributor', 'email.verified'])->prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            // Phase 7: breakdown computed from the real ledger + submissions
            Route::get('/breakdown', [WalletController::class, 'breakdown']);
            Route::post('/withdraw', [WalletController::class, 'withdraw']);
        });

        // Business Endpoints
        Route::middleware('role:business')->prefix('business')->group(function () {
            Route::get('/dashboard', [BusinessCampaignController::class, 'dashboard']);
            Route::get('/campaigns', [BusinessCampaignController::class, 'index']);
            // Round 2: campaign creation + funding gated on verification.
            Route::post('/campaigns', [BusinessCampaignController::class, 'store'])->middleware('email.verified');
            Route::get('/campaigns/{id}', [BusinessCampaignController::class, 'show']);
            Route::patch('/campaigns/{id}/status', [BusinessCampaignController::class, 'updateStatus']);
            Route::post('/campaigns/{id}/fund', [BusinessCampaignController::class, 'fund'])->middleware('email.verified');
            Route::post('/campaigns/{id}/logo', [BusinessCampaignController::class, 'uploadLogo']);
            Route::get('/submissions', [BusinessCampaignController::class, 'submissions']);

            // ==============================================================
            // Phase 9 — CAMPAIGN WIZARD (Worker C): preview -> draft -> launch.
            // Tenant-scoped: a business touches only its own campaigns.
            // Round 2: draft/launch gated on email verification.
            // ==============================================================
            Route::post('/campaigns/wizard/preview', [CampaignWizardController::class, 'preview']);
            Route::post('/campaigns/wizard/draft', [CampaignWizardController::class, 'draft'])->middleware('email.verified');
            Route::patch('/campaigns/wizard/draft/{id}', [CampaignWizardController::class, 'updateDraft'])->middleware('email.verified');
            Route::post('/campaigns/{id}/launch', [CampaignWizardController::class, 'launch'])->middleware('email.verified');

            // ==============================================================
            // Phase 4/13 — BUSINESS TASK CRUD (Worker C): strictly own-tenant
            // via TaskPolicy; rewards band-validated (Phase 12).
            // Round 2: writes gated on email verification.
            // ==============================================================
            Route::get('/tasks', [BusinessTaskController::class, 'index']);
            Route::post('/tasks', [BusinessTaskController::class, 'store'])->middleware('email.verified');
            Route::get('/tasks/{id}', [BusinessTaskController::class, 'show']);
            Route::patch('/tasks/{id}', [BusinessTaskController::class, 'update'])->middleware('email.verified');
            Route::delete('/tasks/{id}', [BusinessTaskController::class, 'destroy'])->middleware('email.verified');

            // Phase 9 — basic business analytics from REAL aggregates.
            Route::get('/analytics', [BusinessTaskController::class, 'analytics']);
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
            Route::put('/templates/{key}', [AdminEmailController::class, 'updateTemplate']);
            Route::post('/templates/{key}/reset', [AdminEmailController::class, 'resetTemplate']);
            Route::get('/logs', [AdminEmailController::class, 'logs']);
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

        // Admin & Super Admin Endpoints
        Route::middleware('role:admin,superadmin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminVerificationController::class, 'dashboard']);
            Route::get('/verification-queue', [AdminVerificationController::class, 'verificationQueue']);
            Route::get('/submissions/{id}', [AdminVerificationController::class, 'submissionDetail']);
            Route::post('/submissions/{id}/decision', [AdminVerificationController::class, 'recordDecision']);
            Route::get('/fraud-alerts', [AdminVerificationController::class, 'fraudAlerts']);
            Route::get('/payouts', [AdminVerificationController::class, 'payouts']);
            Route::post('/payouts/{id}/process', [AdminVerificationController::class, 'processPayout']);
            Route::get('/referrals/overview', [AdminReferralController::class, 'overview']); // Phase 11: read-only referral overview
            Route::get('/referral-rules', [AdminReferralController::class, 'rules']); // Phase 13: view admin-controllable referral rules
            Route::patch('/referral-rules', [AdminReferralController::class, 'updateRules']); // Phase 13: update referral rules (audited)

            // Super Admin Controls
            Route::get('/feature-flags', [AdminSystemController::class, 'featureFlags']);
            Route::patch('/feature-flags/{key}', [AdminSystemController::class, 'updateFeatureFlag']);
            Route::get('/system-settings', [AdminSystemController::class, 'systemSettings']);
            Route::patch('/system-settings', [AdminSystemController::class, 'updateSystemSetting']);
            Route::get('/audit-logs', [AdminSystemController::class, 'auditLogs']);
            Route::get('/users', [AdminSystemController::class, 'users']);
            Route::patch('/users/{id}/status', [AdminSystemController::class, 'updateUserStatus']);
            Route::get('/health', [AdminSystemController::class, 'health']);

            // Demo-request triage (public submissions, admin read only)
            Route::get('/demo-requests', [DemoRequestController::class, 'index']);
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

        Route::middleware(['role:moderator,admin,superadmin', 'permission:manage_task_templates'])->prefix('staff')->group(function () {
            Route::get('/tasks', [AdminTaskController::class, 'index']);
            Route::post('/tasks', [AdminTaskController::class, 'store']);
            Route::patch('/tasks/{id}', [AdminTaskController::class, 'update']);
            Route::delete('/tasks/{id}', [AdminTaskController::class, 'destroy']);

            // Phase 9 — STAFF CAMPAIGN MANAGEMENT (Worker C): cross-tenant
            // list/show, pause/resume/cancel with escrow release, safe
            // delete (drafts only, never once money moved).
            Route::get('/campaigns', [StaffCampaignController::class, 'index']);
            Route::get('/campaigns/{id}', [StaffCampaignController::class, 'show']);
            Route::patch('/campaigns/{id}/status', [StaffCampaignController::class, 'updateStatus']);
            Route::delete('/campaigns/{id}', [StaffCampaignController::class, 'destroy']);
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
            Route::patch('/admins/{id}/permissions', [OpsAdminController::class, 'updatePermissions']);
            Route::get('/permissions', [OpsAdminController::class, 'permissions']);

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

            // Audit log (append-only; read only)
            Route::get('/audit-logs', [OpsSettingsController::class, 'auditLogs']);
        });
    });
});


<?php

use App\Http\Controllers\Api\V1\AdminEmailController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AdminSystemController;
use App\Http\Controllers\Api\V1\AdminVerificationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessCampaignController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 1. Public Configuration & Metadata
    Route::get('/config/brand', [ConfigController::class, 'brandConfig']);
    Route::get('/task-categories', [ConfigController::class, 'categories']);

    // 2. Public Authentication
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    });

    // 3. Public Marketplace Preview
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);

    // 4. Protected Routes
    Route::middleware('auth:sanctum')->group(function () {

        // Auth management
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Profile
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar']);

        // Contributor Endpoints
        Route::middleware('role:contributor')->prefix('contributor')->group(function () {
            Route::get('/dashboard', [TaskController::class, 'contributorDashboard']);
            Route::get('/my-tasks', [TaskController::class, 'myTasks']);
            Route::get('/referrals', [WalletController::class, 'referrals']);
        });

        // Contributor Task Operations
        Route::middleware('role:contributor')->group(function () {
            Route::post('/tasks/{id}/start', [TaskController::class, 'start']);
            Route::post('/tasks/{id}/submit', [TaskController::class, 'submit']);
        });

        // Contributor Wallet Operations
        Route::middleware('role:contributor')->prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::get('/transactions', [WalletController::class, 'transactions']);
            Route::post('/withdraw', [WalletController::class, 'withdraw']);
        });

        // Business Endpoints
        Route::middleware('role:business')->prefix('business')->group(function () {
            Route::get('/dashboard', [BusinessCampaignController::class, 'dashboard']);
            Route::get('/campaigns', [BusinessCampaignController::class, 'index']);
            Route::post('/campaigns', [BusinessCampaignController::class, 'store']);
            Route::get('/campaigns/{id}', [BusinessCampaignController::class, 'show']);
            Route::patch('/campaigns/{id}/status', [BusinessCampaignController::class, 'updateStatus']);
            Route::post('/campaigns/{id}/fund', [BusinessCampaignController::class, 'fund']);
            Route::get('/submissions', [BusinessCampaignController::class, 'submissions']);
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

            // Super Admin Controls
            Route::get('/feature-flags', [AdminSystemController::class, 'featureFlags']);
            Route::patch('/feature-flags/{key}', [AdminSystemController::class, 'updateFeatureFlag']);
            Route::get('/system-settings', [AdminSystemController::class, 'systemSettings']);
            Route::patch('/system-settings', [AdminSystemController::class, 'updateSystemSetting']);
            Route::get('/audit-logs', [AdminSystemController::class, 'auditLogs']);
            Route::get('/users', [AdminSystemController::class, 'users']);
            Route::patch('/users/{id}/status', [AdminSystemController::class, 'updateUserStatus']);
            Route::get('/health', [AdminSystemController::class, 'health']);
        });
    });
});


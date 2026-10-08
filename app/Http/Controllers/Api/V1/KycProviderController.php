<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Kyc\SumsubService;
use App\Services\Staff\StaffScope;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * KYC provider selection (additive — the manual document flow in
 * ProfileController@submitKyc and StaffKycController is untouched).
 *
 * Two verification methods exist per user:
 *   manual — upload documents, staff reviews (default, historic behavior)
 *   sumsub — Sumsub WebSDK check; webhook drives the status
 *
 * Dawood's fallback rule: whenever Sumsub fails for a user, staff flips
 * kyc_method back to 'manual' and the user uploads documents for human
 * review as before. Available to admin and superadmin via the review_kyc
 * permission gate on the routes below.
 */
class KycProviderController extends Controller
{
    public function __construct(
        protected SumsubService $sumsub = new SumsubService()
    ) {}

    /**
     * Whether Sumsub is configured (credentials present). Used by the admin
     * UI to decide whether the Sumsub option is offered.
     */
    public function providerStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'sumsub_configured' => $this->sumsub->isConfigured(),
                'sumsub_level' => $this->sumsub->levelName(),
            ],
        ]);
    }

    /**
     * Staff sets a user's verification method. Switching to 'manual' is the
     * supported fallback when Sumsub fails — it clears the provider state
     * so the user can upload documents for human review.
     */
    public function setMethod(Request $request, string $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'method' => 'required|in:manual,sumsub',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::findOrFail($userId);
        if (!StaffScope::allowsUser($request->user(), $user->id)) {
            return StaffScope::notFound();
        }
        $method = $request->input('method');

        if ($method === 'sumsub' && !$this->sumsub->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Sumsub is not configured. Add the App Token and Secret Key in System Settings first.',
            ], 422);
        }

        $profile = Profile::firstOrCreate(['user_id' => $user->id], ['country_code' => 'GE', 'language' => 'en']);
        $before = $profile->kyc_method ?? 'manual';

        $updates = ['kyc_method' => $method];
        if ($method === 'manual') {
            // Fallback path: drop the provider state so the manual flow
            // (document upload + human review) takes over cleanly.
            $updates['kyc_provider_status'] = null;
        }

        $profile->update($updates);

        AuditLogger::log($request->user(), 'kyc.method_changed', User::class, $user->id, [
            'from' => $before,
            'to' => $method,
        ]);

        return response()->json([
            'success' => true,
            'message' => $method === 'sumsub'
                ? 'Sumsub verification enabled for this user.'
                : 'Switched back to manual review. The user can upload documents.',
            'data' => [
                'kyc_method' => $profile->kyc_method,
                'kyc_status' => $profile->kyc_status,
                'kyc_provider_status' => $profile->kyc_provider_status,
            ],
        ]);
    }

    /**
     * Staff mints a Sumsub WebSDK token for a user (e.g. to open the check
     * on the user's behalf or to test the integration).
     */
    public function staffToken(Request $request, string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        if (!StaffScope::allowsUser($request->user(), $user->id)) {
            return StaffScope::notFound();
        }

        try {
            $token = $this->sumsub->accessToken($user);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 502);
        }

        return response()->json([
            'success' => true,
            'data' => ['token' => $token, 'level' => $this->sumsub->levelName()],
        ]);
    }

    /**
     * The user mints their own Sumsub token (only when their method is sumsub).
     */
    public function userToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = Profile::firstOrCreate(['user_id' => $user->id], ['country_code' => 'GE', 'language' => 'en']);

        if (($profile->kyc_method ?? 'manual') !== 'sumsub') {
            return response()->json([
                'success' => false,
                'message' => 'Sumsub verification is not enabled for your account.',
            ], 422);
        }

        if ($profile->kyc_status === 'verified') {
            return response()->json(['success' => false, 'message' => 'Your identity is already verified.'], 422);
        }

        try {
            $token = $this->sumsub->accessToken($user);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 502);
        }

        return response()->json([
            'success' => true,
            'data' => ['token' => $token, 'level' => $this->sumsub->levelName()],
        ]);
    }

    /**
     * Sumsub webhook receiver. Public endpoint — authenticity comes from the
     * HMAC signature, never from obscurity. Always answers 200 to verified
     * payloads so Sumsub stops retrying.
     */
    public function webhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $digest = $request->header('X-Payload-Digest');
        $alg = $request->header('X-Payload-Digest-Alg');

        if (!$this->sumsub->verifyWebhookSignature($raw, $digest, $alg)) {
            Log::warning('Sumsub webhook signature mismatch', ['ip' => $request->ip()]);

            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $this->sumsub->handleWebhook($payload);

        return response()->json(['success' => true]);
    }

    /**
     * Super-admin stores the Sumsub credentials (DB-backed, never env).
     * Keys: sumsub.app_token, sumsub.secret_key, sumsub.level_name.
     */
    public function saveCredentials(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_token' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
            'level_name' => 'nullable|string|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $actor = $request->user();
        foreach (['app_token', 'secret_key', 'level_name'] as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $key = "sumsub.{$field}";
                $before = SystemSetting::get($key);
                // Never write secrets to the audit log in plain text.
                $masked = $field === 'level_name' ? $value : ($before ? '***updated***' : '***set***');
                SystemSetting::set($key, $value);
                AuditLogger::log($actor, 'kyc.sumsub_credentials_saved', SystemSetting::class, 0, [
                    'key' => $key,
                    'value' => $masked,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Sumsub credentials saved.',
            'data' => ['sumsub_configured' => $this->sumsub->isConfigured()],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\EmailOtpException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Signup hardening — email OTP send / verify endpoints.
 *
 * POST /api/v1/auth/otp/send   { email }        — issues a fresh 6-digit code
 * POST /api/v1/auth/otp/verify { email, code }  — verifies; on success the
 *     email is marked verified, a pending signup account is activated, and
 *     a Sanctum token is issued (user logged in).
 *
 * All failure modes use machine-readable `code` values:
 *  expired | invalid | too_many_attempts | cooldown | rate_limited |
 *  not_found | already_verified | email_failed
 */
class OtpController extends Controller
{
    /**
     * Send (or resend) a verification code. A resend invalidates the
     * previous code and is subject to a 60s cooldown + 5/hour limits.
     */
    public function send(Request $request, EmailOtpService $otps): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->input('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'code' => 'not_found',
                'message' => 'No account found for this email address.',
            ], 404);
        }

        try {
            $expiresIn = $otps->issue($user, $request->ip());
        } catch (EmailOtpException $e) {
            return $this->otpError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent. Check your email.',
            'data' => [
                'expires_in_seconds' => $expiresIn,
                'resend_cooldown_seconds' => EmailOtpService::RESEND_COOLDOWN_SECONDS,
            ],
        ]);
    }

    /**
     * Verify a code. On success the account is activated and a token is
     * issued — this is the moment an email signup "logs in".
     */
    public function verify(Request $request, EmailOtpService $otps): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'The code must be a 6-digit number.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->input('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'code' => 'not_found',
                'message' => 'No account found for this email address.',
            ], 404);
        }

        // Consent proof: a pending signup created under an older terms
        // version must accept the current version before the account is
        // activated (the frontend shows the terms and resends
        // `terms_version`). Legacy accounts with no recorded version are
        // left alone — consent is never backfilled.
        $currentTerms = config('legal.terms_version');
        if ($user->status === 'pending_verification'
            && $user->terms_version !== null
            && $user->terms_version !== $currentTerms
        ) {
            if ($request->input('terms_version') !== $currentTerms) {
                return response()->json([
                    'success' => false,
                    'code' => 'terms_outdated',
                    'message' => 'Our Terms of Service were updated since you registered. Please review and accept the current version to activate your account.',
                ], 422);
            }
            $user->forceFill(['terms_version' => $currentTerms])->save();
        }

        try {
            $user = $otps->verify($user, $request->input('code'), $request->ip());
        } catch (EmailOtpException $e) {
            return $this->otpError($e);
        }

        // Single-active-session, same as password login.
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Email verified. Welcome to eBizEarn.',
            'data' => [
                'user' => $user->load(['profile', 'wallet', 'business'])->withClientPermissions(),
                'token' => $token,
            ],
        ]);
    }

    protected function otpError(EmailOtpException $e): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->data ? ['data' => $e->data] : []), $e->status);
    }
}

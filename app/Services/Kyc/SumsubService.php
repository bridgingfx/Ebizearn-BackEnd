<?php

namespace App\Services\Kyc;

use App\Models\Profile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sumsub KYC provider integration (additive — the manual document flow is
 * untouched; profiles default to kyc_method = 'manual').
 *
 * Credentials live in the system_settings table (NOT env), following the
 * platform's existing pattern for provider secrets:
 *   sumsub.app_token   — App Token from cockpit.sumsub.com
 *   sumsub.secret_key  — Secret Key paired with the token
 *   sumsub.level_name  — verification level, e.g. "basic-kyc-level"
 *
 * Flow:
 *  1. Staff sets a user's kyc_method to 'sumsub' (KycProviderController).
 *  2. Backend creates a Sumsub applicant (externalUserId = user uuid) and
 *     mints a short-lived access token for the WebSDK.
 *  3. The user completes verification in Sumsub's flow.
 *  4. Sumsub POSTs a signed webhook -> kyc_status is updated.
 *  5. If Sumsub rejects/fails, staff flips kyc_method back to 'manual' and
 *     the user uploads documents for human review as before.
 */
class SumsubService
{
    protected const BASE_URL = 'https://api.sumsub.com';

    public function isConfigured(): bool
    {
        return (bool) $this->appToken() && (bool) $this->secretKey();
    }

    public function levelName(): string
    {
        return SystemSetting::get('sumsub.level_name') ?: 'basic-kyc-level';
    }

    /**
     * Create (or reuse) the Sumsub applicant for this user and store the
     * applicant ID on the profile. Idempotent: an existing ref is reused.
     *
     * @throws Exception when Sumsub is not configured or the API errors.
     */
    public function ensureApplicant(User $user): string
    {
        $this->assertConfigured();

        $profile = Profile::firstOrCreate(['user_id' => $user->id], ['country_code' => 'GE', 'language' => 'en']);

        if ($profile->kyc_provider_ref) {
            return $profile->kyc_provider_ref;
        }

        $body = [
            'externalUserId' => $user->uuid,
            'info' => [
                'firstName' => $this->firstName($user->name),
                'lastName' => $this->lastName($user->name),
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ];

        $response = $this->signedRequest(
            'POST',
            '/resources/applicants?levelName=' . urlencode($this->levelName()),
            $body
        );

        if (!$response->successful()) {
            Log::warning('Sumsub applicant creation failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Sumsub applicant creation failed: ' . $response->body(), 502);
        }

        $applicantId = (string) ($response->json('id') ?? '');
        if ($applicantId === '') {
            throw new Exception('Sumsub did not return an applicant ID.', 502);
        }

        $profile->update([
            'kyc_method' => 'sumsub',
            'kyc_provider_ref' => $applicantId,
            'kyc_provider_status' => $response->json('review.reviewStatus'),
        ]);

        AuditLogger::log($user, 'kyc.sumsub_applicant_created', User::class, $user->id, [
            'applicant_id' => $applicantId,
        ]);

        return $applicantId;
    }

    /**
     * Mint a short-lived WebSDK access token for the user's applicant.
     *
     * @throws Exception
     */
    public function accessToken(User $user): string
    {
        $this->assertConfigured();

        $applicantId = $this->ensureApplicant($user);

        $response = $this->signedRequest(
            'POST',
            '/resources/accessTokens?userId=' . urlencode($user->uuid)
                . '&levelName=' . urlencode($this->levelName())
                . '&ttlInSecs=600',
            ['applicantId' => $applicantId]
        );

        if (!$response->successful()) {
            Log::warning('Sumsub access token failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Could not start Sumsub verification. Please try manual verification.', 502);
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            throw new Exception('Sumsub did not return an access token.', 502);
        }

        return $token;
    }

    /**
     * Verify the webhook signature. Sumsub sends:
     *   X-Payload-Digest: hex HMAC-SHA256(secret, rawBody)
     *   X-Payload-Digest-Alg: HMAC_SHA256
     */
    public function verifyWebhookSignature(string $rawBody, ?string $digest, ?string $alg): bool
    {
        if (!$digest || !$this->secretKey()) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->secretKey());

        return hash_equals(strtolower($expected), strtolower(trim($digest)));
    }

    /**
     * Apply a Sumsub webhook to the matching profile. Only touches
     * kyc_status / kyc_provider_status — never the manual review columns.
     */
    public function handleWebhook(array $payload): void
    {
        $applicantId = (string) ($payload['applicantId'] ?? '');
        $type = (string) ($payload['type'] ?? '');

        if ($applicantId === '' || $type === '') {
            return;
        }

        $profile = Profile::where('kyc_provider_ref', $applicantId)->first();
        if (!$profile) {
            Log::info('Sumsub webhook for unknown applicant', ['applicantId' => $applicantId]);
            return;
        }

        $reviewStatus = (string) ($payload['reviewStatus'] ?? '');
        $reviewAnswer = (string) ($payload['reviewResult']['reviewAnswer'] ?? '');

        $updates = ['kyc_provider_status' => $reviewStatus ?: $type];

        // Terminal states drive kyc_status; anything else just records progress.
        if ($type === 'applicantReviewed') {
            if ($reviewAnswer === 'GREEN') {
                $updates['kyc_status'] = 'verified';
                $updates['kyc_verified_at'] = now();
                $updates['kyc_rejection_reason'] = null;
            } elseif ($reviewAnswer === 'RED') {
                // Rejected by Sumsub — stays visible so staff can flip the
                // user back to manual review (the Dawood fallback rule).
                $updates['kyc_status'] = 'rejected';
                $updates['kyc_rejection_reason'] = 'Sumsub verification failed. Staff can switch you to manual review.';
            }
        }

        $profile->update($updates);

        AuditLogger::log(null, 'kyc.sumsub_webhook', User::class, $profile->user_id, [
            'type' => $type,
            'review_status' => $reviewStatus,
            'review_answer' => $reviewAnswer,
        ]);

        Log::info('Sumsub webhook applied', [
            'user_id' => $profile->user_id,
            'type' => $type,
            'answer' => $reviewAnswer,
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new Exception(
                'Sumsub is not configured. A super admin must add the App Token and Secret Key in System Settings.',
                503
            );
        }
    }

    protected function appToken(): ?string
    {
        return SystemSetting::get('sumsub.app_token') ?: null;
    }

    protected function secretKey(): ?string
    {
        return SystemSetting::get('sumsub.secret_key') ?: null;
    }

    /**
     * Sumsub request signing: lowercase hex HMAC-SHA256(secret, ts + METHOD + path + body).
     */
    protected function signedRequest(string $method, string $path, array $body = [])
    {
        $ts = (string) time();
        $json = $body === [] ? '' : json_encode($body);
        $signature = hash_hmac('sha256', $ts . strtoupper($method) . $path, $this->secretKey());

        return Http::withHeaders([
            'X-App-Token' => $this->appToken(),
            'X-App-Access-Ts' => $ts,
            'X-App-Access-Sig' => $signature,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->withBody($json, 'application/json')
            ->send(strtoupper($method), self::BASE_URL . $path);
    }

    protected function firstName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));

        return $parts[0] ?? 'User';
    }

    protected function lastName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        array_shift($parts);

        return implode(' ', $parts) ?: 'User';
    }
}

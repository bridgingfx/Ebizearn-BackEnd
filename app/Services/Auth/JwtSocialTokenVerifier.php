<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Round 2 — server-side verification of Google / Apple ID tokens.
 *
 * Both providers publish their public signing keys as JWKS. Keys are
 * fetched over HTTPS and cached (1h, shorter than the providers' key
 * rotation cadence); on cache-miss or key-not-found we refetch once before
 * giving up, so rotation never hard-fails verification.
 *
 * Checks performed for every token:
 *  - RS256 signature verifies against the provider's current public keys
 *    (matched on the JWT `kid` header)
 *  - aud equals the configured client ID (Google) / Service ID (Apple)
 *  - exp is in the future (with the library's default leeway for clock skew)
 *  - iss is the provider's issuer (Google allows both host variants)
 *
 * Missing or placeholder client IDs (Dawood has not supplied the real
 * credentials yet) make verification fail closed — every request 401s —
 * rather than silently accepting tokens.
 */
class JwtSocialTokenVerifier implements SocialTokenVerifier
{
    private const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';

    private const GOOGLE_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    private const APPLE_ISSUER = 'https://appleid.apple.com';

    public function verifyGoogle(string $idToken): array
    {
        $audience = app(SocialAuthSettings::class)->clientId('google');

        if ($audience === '' || str_starts_with($audience, 'YOUR_')) {
            throw new SocialTokenVerificationException('Google client ID is not configured.');
        }

        $claims = $this->verifyWithJwks($idToken, self::GOOGLE_CERTS_URL, 'google-certs');

        $this->assertIssuer($claims, self::GOOGLE_ISSUERS, 'google');
        $this->assertAudience($claims, $audience, 'google');

        return $this->claimsArray($claims);
    }

    public function verifyApple(string $idToken): array
    {
        $audience = app(SocialAuthSettings::class)->clientId('apple');

        if ($audience === '' || str_starts_with($audience, 'YOUR_')) {
            throw new SocialTokenVerificationException('Apple client ID is not configured.');
        }

        $claims = $this->verifyWithJwks($idToken, self::APPLE_KEYS_URL, 'apple-keys');

        $this->assertIssuer($claims, [self::APPLE_ISSUER], 'apple');
        $this->assertAudience($claims, $audience, 'apple');

        return $this->claimsArray($claims);
    }

    /**
     * Verify the RS256 signature against the provider's JWKS and return the
     * decoded payload as an object. Handles one key-rotation retry.
     */
    private function verifyWithJwks(string $idToken, string $jwksUrl, string $cacheKey): object
    {
        $attempt = function () use ($idToken, $jwksUrl, $cacheKey): object {
            $jwks = $this->jwks($jwksUrl, $cacheKey);
            $kid = $this->kid($idToken);

            // parseKeySet returns Key objects indexed by kid; JWT::decode
            // picks the one matching the token's kid header.
            $keys = JWK::parseKeySet(['keys' => $jwks]);

            if (!isset($keys[$kid])) {
                throw new SocialTokenVerificationException('Signing key not found.');
            }

            return JWT::decode($idToken, $keys);
        };

        try {
            return $attempt();
        } catch (Throwable $first) {
            // Key may have rotated between cache fill and now: refetch once.
            Cache::forget("social_jwks.{$cacheKey}");

            try {
                return $attempt();
            } catch (Throwable $e) {
                Log::warning('Social token verification failed', [
                    'error' => $e->getMessage(),
                ]);
                throw new SocialTokenVerificationException('Token verification failed.');
            }
        }
    }

    /**
     * Fetch and cache the provider's JWKS (public keys only).
     */
    private function jwks(string $url, string $cacheKey): array
    {
        return Cache::remember("social_jwks.{$cacheKey}", 3600, function () use ($url) {
            try {
                $response = Http::timeout(10)->get($url);
            } catch (Throwable $e) {
                Log::warning('Social JWKS fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
                throw new SocialTokenVerificationException('Could not reach the identity provider.');
            }

            if (!$response->successful()) {
                throw new SocialTokenVerificationException('Could not reach the identity provider.');
            }

            $keys = $response->json('keys');

            if (!is_array($keys) || $keys === []) {
                throw new SocialTokenVerificationException('Identity provider returned no keys.');
            }

            return $keys;
        });
    }

    private function kid(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new SocialTokenVerificationException('Malformed token.');
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')) ?: '', true);

        if (!is_array($header) || empty($header['kid'])) {
            throw new SocialTokenVerificationException('Token has no key id.');
        }

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new SocialTokenVerificationException('Unexpected signing algorithm.');
        }

        return (string) $header['kid'];
    }

    private function assertIssuer(object $claims, array $allowed, string $provider): void
    {
        if (!in_array($claims->iss ?? null, $allowed, true)) {
            throw new SocialTokenVerificationException("Invalid {$provider} token issuer.");
        }
    }

    private function assertAudience(object $claims, string $audience, string $provider): void
    {
        // aud may be a string or an array (Apple uses a string Service ID).
        $aud = $claims->aud ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];

        if (!in_array($audience, $audiences, true)) {
            throw new SocialTokenVerificationException("Invalid {$provider} token audience.");
        }
    }

    /**
     * @return array{sub: string, email: ?string, email_verified: ?bool, name: ?string}
     */
    private function claimsArray(object $claims): array
    {
        if (empty($claims->sub) || !is_string($claims->sub)) {
            throw new SocialTokenVerificationException('Token has no subject.');
        }

        $email = isset($claims->email) && is_string($claims->email) ? $claims->email : null;

        return [
            'sub' => $claims->sub,
            // Apple hides the email after first authorization: callers must
            // cope with null (client-supplied email on first login, or match
            // the stable `sub` against an already-linked account).
            'email' => $email,
            'email_verified' => isset($claims->email_verified) ? (bool) $claims->email_verified : null,
            'name' => isset($claims->name) && is_string($claims->name) ? $claims->name : null,
        ];
    }
}

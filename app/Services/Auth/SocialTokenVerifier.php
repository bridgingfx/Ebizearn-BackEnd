<?php

namespace App\Services\Auth;

/**
 * Round 2 — social ID-token verification contract.
 *
 * Verifies an OpenID Connect ID token server-side and returns the verified
 * claims: ['sub' => string, 'email' => ?string, 'email_verified' => ?bool,
 * 'name' => ?string]. Throws SocialTokenVerificationException on ANY
 * failure (bad signature, wrong audience, expired, wrong issuer, network
 * error fetching keys) so callers treat every failure the same: 401.
 */
interface SocialTokenVerifier
{
    /**
     * @return array{sub: string, email: ?string, email_verified: ?bool, name: ?string}
     *
     * @throws SocialTokenVerificationException
     */
    public function verifyGoogle(string $idToken): array;

    /**
     * @return array{sub: string, email: ?string, email_verified: ?bool, name: ?string}
     *
     * @throws SocialTokenVerificationException
     */
    public function verifyApple(string $idToken): array;
}

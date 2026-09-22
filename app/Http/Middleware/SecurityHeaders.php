<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Round 2 — security headers for the JSON API.
 *
 * HSTS (only meaningful once the domain is served over HTTPS — the
 * header is emitted regardless; plain-HTTP local dev simply ignores it),
 * clickjacking denial, MIME-sniffing denial, a restrictive CSP for a
 * JSON-only API (no active content of our own is ever served), and a
 * locked-down Permissions-Policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
        );
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}

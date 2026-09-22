<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Round 2 — email verification gate.
 *
 * Money and task write paths (dashboards, wallet actions, task start/
 * submit, campaign launch/funding, withdrawals) require a verified email.
 * Answers 403 with a machine-readable code so the frontend can route the
 * user to the verify-email screen instead of a generic error page.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->email_verified_at) {
            return response()->json([
                'success' => false,
                'code' => 'email_not_verified',
                'message' => 'Please verify your email address to continue.',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects Sanctum tokens past their expires_at.
 * Protects short-lived tokens (e.g. 30-min impersonation tokens).
 */
class RejectExpiredTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && $token->expires_at && $token->expires_at->isPast()) {
                $token->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Session expired. Please sign in again.',
                ], 401);
            }
        }

        return $next($request);
    }
}

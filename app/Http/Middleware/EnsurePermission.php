<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2/13: granular permission gate. Super Admin implicitly holds every
 * permission; everyone else must hold each listed permission (via direct
 * grant or their primary role's grants). Usage: ->middleware('permission:review_submissions,manage_users')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        foreach ($permissions as $permission) {
            if (!$user->hasPermission(trim($permission))) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have the required permission: ' . $permission,
                ], 403);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Permission;
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
            $permission = trim($permission);
            if (!$user->hasPermission($permission)) {
                $label = Permission::catalog()[$permission] ?? $permission;

                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not allowed to: ' . $label . '. Contact support if you think this is a mistake.',
                    'code' => 'permission_denied',
                    'permission' => $permission,
                ], 403);
            }
        }

        return $next($request);
    }
}

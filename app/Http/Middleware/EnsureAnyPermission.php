<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like EnsurePermission, but passes when the user holds ANY of the listed
 * permissions. For endpoints shared by several admin sections (e.g. the
 * user list feeds both "Users & KYC" and "Businesses").
 * Usage: ->middleware('permission.any:manage_users,manage_businesses')
 */
class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission(trim($permission))) {
                return $next($request);
            }
        }

        $labels = array_map(fn ($p) => Permission::catalog()[trim($p)] ?? trim($p), $permissions);

        return response()->json([
            'success' => false,
            'message' => 'Your account is not allowed to: ' . implode(' / ', $labels) . '. Contact support if you think this is a mistake.',
            'code' => 'permission_denied',
            'permission' => trim($permissions[0] ?? ''),
        ], 403);
    }
}

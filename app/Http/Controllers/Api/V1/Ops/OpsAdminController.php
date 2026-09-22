<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2: Super-Admin-only staff management.
 * Admin/Moderator accounts can ONLY be created here — never via public
 * registration (AuthController rejects those roles with 422) and never via
 * this endpoint with role=superadmin (use `php artisan superadmin:create`).
 */
class OpsAdminController extends Controller
{
    /**
     * List staff accounts (admin / moderator / superadmin).
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::whereIn('role', ['admin', 'moderator', 'superadmin'])
            ->with('directPermissions:id,name,label')
            ->orderBy('role')
            ->orderBy('name')
            ->paginate(25);

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Create an admin or moderator account with permission assignment.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12',
            'role' => 'required|in:admin,moderator',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        if (!empty($validated['permissions'])) {
            $user->syncPermissions($validated['permissions']);
        }

        AuditLogger::log(
            $request->user(),
            'staff.created',
            User::class,
            $user->id,
            [
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $validated['permissions'] ?? [],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => ucfirst($user->role) . ' account created.',
            'data' => $user->load('directPermissions:id,name,label'),
        ], 201);
    }

    /**
     * Replace a staff member's direct permission grants.
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::whereIn('role', ['admin', 'moderator', 'superadmin'])->findOrFail($id);

        if ((int) $user->id === (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own permissions.',
            ], 422);
        }

        $before = $user->directPermissions()->pluck('permissions.name')->all();
        $user->syncPermissions($validator->validated()['permissions']);

        AuditLogger::log(
            $request->user(),
            'staff.permissions_updated',
            User::class,
            $user->id,
            ['permissions' => $validator->validated()['permissions']],
            ['permissions' => $before]
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated.',
            'data' => $user->load('directPermissions:id,name,label'),
        ]);
    }

    /**
     * Permission catalog (for the ops UI).
     */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Permission::orderBy('name')->get(),
        ]);
    }
}

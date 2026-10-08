<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Super Admin permission management for every role:
 *  - role matrix: which permissions each role (admin, moderator,
 *    contributor, business) holds by default;
 *  - per-user overrides: extra grants or explicit denies on one account.
 * Super Admin itself always holds everything and is not editable.
 */
class OpsPermissionController extends Controller
{
    /** GET /ops/roles — catalog + current grants per editable role. */
    public function roles(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')
            ->whereIn('name', Permission::EDITABLE_ROLES)
            ->get()
            ->sortBy(fn (Role $r) => array_search($r->name, Permission::EDITABLE_ROLES, true))
            ->values()
            ->map(fn (Role $r) => [
                'name' => $r->name,
                'label' => $r->label,
                'users_count' => User::where('role', $r->name)->count(),
                'permissions' => $r->permissions->pluck('name')->sort()->values(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'permissions' => $this->catalog(),
            ],
        ]);
    }

    /** PUT /ops/roles/{name}/permissions  { permissions: string[] } */
    public function updateRole(Request $request, string $name): JsonResponse
    {
        if (!in_array($name, Permission::EDITABLE_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'This role cannot be edited.'], 422);
        }

        $actor = $request->user();

        // Moderators set access per user only — never role-wide.
        if (!$actor->isSuperAdmin() && $actor->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Only Super Admin and admins can change role-wide access.'], 403);
        }

        // Guard: an admin may manage roles, but only superadmin may change
        // what the admin role itself can do (prevents privilege escalation).
        if (!$actor->isSuperAdmin() && in_array($name, ['admin', 'superadmin'], true)) {
            return response()->json(['success' => false, 'message' => 'Only Super Admin can change the admin role.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => 'present|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $names = array_values(array_unique($validator->validated()['permissions']));

        // Guard: an admin cannot grant a permission they do not have themselves.
        if (!$actor->isSuperAdmin()) {
            $actorPermissions = $actor->effectivePermissions();
            $excess = array_diff($names, $actorPermissions);
            if ($excess !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot grant permissions you do not have: ' . implode(', ', $excess),
                ], 403);
            }
        }

        $role = Role::where('name', $name)->firstOrFail();
        $before = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

        DB::transaction(function () use ($role, $names) {
            $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id')->all());
        });

        sort($names);
        AuditLogger::log($request->user(), 'role.permissions_updated', Role::class, $role->id, [], ['permissions' => $before], ['permissions' => $names]);

        return response()->json([
            'success' => true,
            'message' => "{$role->label} permissions saved.",
            'data' => ['name' => $role->name, 'label' => $role->label, 'permissions' => $names],
        ]);
    }

    /** GET /ops/users/{id}/permissions */
    public function user(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if (!StaffScope::allowsUser($request->user(), $user->id)
            || (!$user->isSuperAdmin() && !StaffScope::canSetPermissionsFor($request->user(), $user))) {
            return StaffScope::notFound();
        }

        return response()->json(['success' => true, 'data' => $this->presentUser($user, $request->user())]);
    }

    /** PUT /ops/users/{id}/permissions  { grants: string[], denies: string[] } */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'grants' => 'present|array',
            'grants.*' => 'string|exists:permissions,name',
            'denies' => 'present|array',
            'denies.*' => 'string|exists:permissions,name',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);

        if ($user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Super Admin permissions cannot be changed.'], 422);
        }
        if ((int) $user->id === (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You cannot change your own permissions.'], 422);
        }

        $actor = $request->user();

        // Guard: staff only manage roles below their own — an admin manages
        // moderators, businesses and contributors; a moderator businesses
        // and contributors. Only superadmin touches admin accounts.
        if (!StaffScope::canSetPermissionsFor($actor, $user)) {
            return response()->json(['success' => false, 'message' => 'You cannot change permissions of this account.'], 403);
        }
        // Assigned-users scope: a scoped admin only manages their own users.
        if (!StaffScope::allowsUser($actor, $user->id)) {
            return StaffScope::notFound();
        }

        $data = $validator->validated();

        // Delegation guard: an admin can only hand out what Super Admin gave
        // them. Grants Super Admin already placed on the user may stay.
        if (!$actor->isSuperAdmin()) {
            $added = array_diff($data['grants'], $user->grantedPermissionNames());
            $excess = array_values(array_diff($added, $actor->effectivePermissions()));
            if ($excess !== []) {
                $labels = array_map(fn ($p) => Permission::catalog()[$p] ?? $p, $excess);

                return response()->json([
                    'success' => false,
                    'message' => 'You can only give permissions you have yourself. Not allowed: ' . implode(', ', $labels) . '.',
                    'code' => 'permission_not_held',
                ], 403);
            }
        }
        $before = ['grants' => $user->grantedPermissionNames(), 'denies' => $user->deniedPermissionNames()];

        $user->syncPermissionOverrides($data['grants'], $data['denies']);

        $after = ['grants' => $user->grantedPermissionNames(), 'denies' => $user->deniedPermissionNames()];
        AuditLogger::log($request->user(), 'user.permissions_updated', User::class, $user->id, [], $before, $after);

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated.',
            'data' => $this->presentUser($user),
        ]);
    }

    private function catalog(): array
    {
        $defs = Permission::definitions();
        $groupOf = fn (string $name) => $defs[$name][1] ?? 'other';

        return Permission::orderBy('name')->get(['name', 'label'])
            ->map(fn (Permission $p) => [
                'name' => $p->name,
                'label' => $defs[$p->name][0] ?? $p->label,
                'group' => $groupOf($p->name),
            ])
            ->sortBy(fn ($p) => array_search($p['name'], array_keys($defs), true) === false ? 999 : array_search($p['name'], array_keys($defs), true))
            ->values()
            ->all();
    }

    private function presentUser(User $user, ?User $actor = null): array
    {
        $actor ??= request()->user();

        return [
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'editable' => !$user->isSuperAdmin(),
            'role_permissions' => $user->rolePermissionNames(),
            'grants' => $user->grantedPermissionNames(),
            'denies' => $user->deniedPermissionNames(),
            'effective' => $user->effectivePermissions(),
            'permissions' => $this->catalog(),
            // What the signed-in staff member may newly Allow (delegation):
            // Super Admin everything, an admin only what they hold.
            'grantable' => $actor && !$actor->isSuperAdmin() ? $actor->effectivePermissions() : null,
        ];
    }
}

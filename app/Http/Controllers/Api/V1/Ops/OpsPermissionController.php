<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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

        $validator = Validator::make($request->all(), [
            'permissions' => 'present|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $role = Role::where('name', $name)->firstOrFail();
        $names = array_values(array_unique($validator->validated()['permissions']));
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
    public function user(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->presentUser($user)]);
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

        $data = $validator->validated();
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

    private function presentUser(User $user): array
    {
        return [
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'editable' => !$user->isSuperAdmin(),
            'role_permissions' => $user->rolePermissionNames(),
            'grants' => $user->grantedPermissionNames(),
            'denies' => $user->deniedPermissionNames(),
            'effective' => $user->effectivePermissions(),
            'permissions' => $this->catalog(),
        ];
    }
}

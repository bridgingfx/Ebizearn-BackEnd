<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Super Admin: assign contributors / businesses / moderators to a staff
 * account (admin or moderator). A staff account with assigned users only
 * sees and manages those users (App\Services\Staff\StaffScope); removing
 * every assignment gives it full access again.
 */
class OpsAssignmentController extends Controller
{
    /** GET /ops/staff/{id}/assignments */
    public function show(string $id): JsonResponse
    {
        $staff = $this->staff($id);

        return response()->json(['success' => true, 'data' => $this->present($staff)]);
    }

    /** PUT /ops/staff/{id}/assignments  { user_ids: int[] } — replaces the set. */
    public function update(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($id);

        $validator = Validator::make($request->all(), [
            'user_ids' => 'present|array|max:5000',
            'user_ids.*' => 'integer|distinct',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $ids = array_map('intval', $validator->validated()['user_ids']);
        $allowedRoles = StaffScope::MANAGEABLE_ROLES[$staff->role];
        $users = User::whereIn('id', $ids)->get(['id', 'role', 'managed_by']);

        $invalid = $users->reject(fn (User $u) => in_array($u->role, $allowedRoles, true))->pluck('id');
        if ($invalid->isNotEmpty() || $users->count() !== count($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'A ' . $staff->role . ' can only be assigned ' . implode(', ', $allowedRoles) . ' accounts.',
            ], 422);
        }

        $before = User::where('managed_by', $staff->id)->pluck('id')->all();

        DB::transaction(function () use ($staff, $ids) {
            User::where('managed_by', $staff->id)->whereNotIn('id', $ids)->update(['managed_by' => null]);
            if ($ids !== []) {
                // Moves a user away from any other staff member they had.
                User::whereIn('id', $ids)->update(['managed_by' => $staff->id]);
            }
        });

        AuditLogger::log($request->user(), 'staff.assignments_updated', User::class, $staff->id, [],
            ['user_ids' => $before], ['user_ids' => $ids]);

        return response()->json([
            'success' => true,
            'message' => $ids === []
                ? "{$staff->name} has no assigned users — full access to all users."
                : "{$staff->name} now manages " . count($ids) . ' assigned user' . (count($ids) === 1 ? '' : 's') . ' only.',
            'data' => $this->present($staff->fresh()),
        ]);
    }

    private function staff(string $id): User
    {
        $staff = User::findOrFail($id);
        abort_unless(isset(StaffScope::MANAGEABLE_ROLES[$staff->role]), 422, 'Users can only be assigned to admin or moderator accounts.');

        return $staff;
    }

    private function present(User $staff): array
    {
        return [
            'staff' => $staff->only(['id', 'name', 'email', 'role']),
            'assignable_roles' => StaffScope::MANAGEABLE_ROLES[$staff->role],
            'users' => User::where('managed_by', $staff->id)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'status']),
        ];
    }
}

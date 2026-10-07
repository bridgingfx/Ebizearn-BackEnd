<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department management (additive 2026-10-07).
 *
 * Superadmin: full CRUD. Admin with manage_roles: list only.
 * Departments organize staff; roles can be scoped to a department.
 */
class OpsDepartmentController extends Controller
{
    /** GET /ops/departments — active departments for pickers. */
    public function index(): JsonResponse
    {
        $departments = Department::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'label']);

        return response()->json(['success' => true, 'data' => $departments]);
    }

    /** GET /ops/departments/manage — every department with user counts. */
    public function manage(): JsonResponse
    {
        $departments = Department::withCount('users')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $departments]);
    }

    /** POST /ops/departments { name, label? } — superadmin only. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80|unique:departments,name',
            'label' => 'nullable|string|max:120',
        ]);

        $department = Department::create([
            'name' => trim($data['name']),
            'label' => $data['label'] ?? null,
            'is_active' => true,
            'sort_order' => (Department::max('sort_order') ?? 0) + 1,
        ]);

        $this->audit($request, 'department.created', $department);

        return response()->json([
            'success' => true,
            'message' => $department->name . ' department created.',
            'data' => $department,
        ], 201);
    }

    /** PATCH /ops/departments/{id} — superadmin only. */
    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:80|unique:departments,name,' . $id,
            'label' => 'nullable|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]);

        $before = $department->only(['name', 'label', 'is_active']);
        $department->update($data);

        $this->audit($request, 'department.updated', $department, $before);

        return response()->json(['success' => true, 'data' => $department->fresh()]);
    }

    /** DELETE /ops/departments/{id} — superadmin only; users keep their accounts, department unset. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $this->audit($request, 'department.deleted', $department);
        $department->delete();

        return response()->json(['success' => true, 'message' => 'Department deleted.']);
    }

    protected function audit(Request $request, string $action, Department $department, array $before = []): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => Department::class,
            'entity_id' => $department->id,
            'before_state_json' => $before,
            'after_state_json' => $department->only(['name', 'label', 'is_active']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}

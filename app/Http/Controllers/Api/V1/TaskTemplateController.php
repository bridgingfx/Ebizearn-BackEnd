<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\TaskTemplate;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Task Library templates.
 *
 * - Reading (view_task_library): businesses see active templates marked
 *   visible_to_business; admins/moderators see active ones marked
 *   visible_to_admin. Anyone holding manage_task_library (Super Admin
 *   implicitly) sees every template, hidden or inactive, so they can manage it.
 * - Writing (manage_task_library): create / update / delete. Audited.
 */
class TaskTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = TaskTemplate::query()->ordered();
        $canManage = $user->role !== 'business' && $user->hasPermission(Permission::MANAGE_TASK_LIBRARY);

        if (!$canManage) {
            $query->where('is_active', true)
                ->where($user->role === 'business' ? 'visible_to_business' : 'visible_to_admin', true);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
            'meta' => ['can_manage' => $canManage],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->validator($request->all(), true);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $request->user()->id;
        $data['sort_order'] ??= ((int) TaskTemplate::max('sort_order')) + 10;

        $template = TaskTemplate::create($data);

        AuditLogger::log($request->user(), 'task_template.created', TaskTemplate::class, $template->id, ['name' => $template->name]);

        return response()->json(['success' => true, 'message' => 'Template created.', 'data' => $template], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = TaskTemplate::findOrFail($id);

        $validator = $this->validator($request->all(), false);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $before = $template->only(array_keys($validator->validated()));
        $template->update($validator->validated());

        AuditLogger::log($request->user(), 'task_template.updated', TaskTemplate::class, $template->id, [], $before, $validator->validated());

        return response()->json(['success' => true, 'message' => 'Template saved.', 'data' => $template->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = TaskTemplate::findOrFail($id);
        $template->delete();

        AuditLogger::log($request->user(), 'task_template.deleted', TaskTemplate::class, $id, ['name' => $template->name]);

        return response()->json(['success' => true, 'message' => 'Template deleted.']);
    }

    protected function validator(array $input, bool $isCreate): \Illuminate\Contracts\Validation\Validator
    {
        $required = $isCreate ? 'required' : 'sometimes';

        return Validator::make($input, [
            'name' => $required . '|string|max:120',
            'description' => $required . '|string|max:1000',
            'icon' => ['sometimes', 'string', Rule::in(TaskTemplate::ICONS)],
            'duration_label' => 'nullable|string|max:64',
            'reward_label' => 'nullable|string|max:64',
            'template_key' => 'nullable|string|max:64',
            'task_type_key' => 'nullable|string|exists:task_types,key',
            'platform' => 'nullable|string|max:64',
            'instructions' => 'nullable|string|max:5000',
            'visible_to_business' => 'sometimes|boolean',
            'visible_to_admin' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:100000',
        ]);
    }
}

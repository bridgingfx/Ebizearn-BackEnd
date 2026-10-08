<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskTemplate;
use App\Models\TaskType;
use App\Models\WizardPreset;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Task Library → Dropdown lists: add and remove the options
 * of the managed dropdowns (task types, categories, wizard presets).
 * Editing task types / categories reuses the existing endpoints.
 *
 * Removing an option that is already in use would break existing tasks
 * and campaigns, so it is switched off (inactive) instead of deleted.
 */
class OpsDropdownController extends Controller
{
    // ---------------------------------------------------------- task types

    /** POST /ops/task-types { name, key?, reward_band_min_cents, reward_band_max_cents, description? } */
    public function storeTaskType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'description' => 'nullable|string|max:1000',
            'reward_band_min_cents' => 'required|integer|min:1|max:100000',
            'reward_band_max_cents' => 'required|integer|gte:reward_band_min_cents|max:100000',
        ], ['reward_band_max_cents.gte' => 'The maximum reward must be at least the minimum.']);

        $key = ($data['key'] ?? null) ?: Str::slug($data['name'], '_');
        if (TaskType::where('key', $key)->exists()) {
            return response()->json(['success' => false, 'message' => "A task type with the key \"{$key}\" already exists.", 'errors' => ['key' => ['Already used.']]], 422);
        }

        $type = TaskType::create([
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'reward_band_min_cents' => $data['reward_band_min_cents'],
            'reward_band_max_cents' => $data['reward_band_max_cents'],
            'proof_required_json' => ['screenshot'],
            'is_allowed' => true,
            'is_active' => true,
        ]);

        AuditLogger::log($request->user(), 'task_type.created', TaskType::class, $type->id, ['key' => $key, 'name' => $type->name]);

        return response()->json(['success' => true, 'message' => "Task type \"{$type->name}\" added.", 'data' => $type], 201);
    }

    /** DELETE /ops/task-types/{key} — deleted when unused, otherwise switched off. */
    public function destroyTaskType(Request $request, string $key): JsonResponse
    {
        $type = TaskType::where('key', $key)->firstOrFail();
        $inUse = Task::withTrashed()->where('task_type_id', $type->id)->exists()
            || TaskTemplate::where('task_type_key', $type->key)->exists()
            || WizardPreset::where('task_type_key', $type->key)->exists();

        return $this->removeOrDeactivate($request, $type, $inUse, 'task_type', $type->name);
    }

    // ---------------------------------------------------------- categories

    /** DELETE /ops/task-categories/{id} — deleted when unused, otherwise switched off. */
    public function destroyCategory(Request $request, int $id): JsonResponse
    {
        $category = TaskCategory::findOrFail($id);
        $inUse = Campaign::withTrashed()->where('category_id', $category->id)->exists()
            || Task::withTrashed()->where('category_id', $category->id)->exists();

        return $this->removeOrDeactivate($request, $category, $inUse, 'settings.task_category', $category->name);
    }

    // ---------------------------------------------------------- wizard presets

    /** GET /wizard-presets — public, active presets for the wizard and template form. */
    public function publicPresets(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => WizardPreset::with('category:id,name')->where('is_active', true)->orderBy('sort_order')->orderBy('label')
                ->get(['id', 'key', 'label', 'task_type_key', 'category_id', 'platform']),
        ]);
    }

    /** GET /ops/wizard-presets */
    public function presets(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => WizardPreset::with('category:id,name')->orderBy('sort_order')->orderBy('label')->get()]);
    }

    /** POST /ops/wizard-presets */
    public function storePreset(Request $request): JsonResponse
    {
        $data = $this->validatePreset($request);
        $data['key'] = $data['key'] ?? Str::slug($data['label'], '_');
        if (WizardPreset::where('key', $data['key'])->exists()) {
            return response()->json(['success' => false, 'message' => "A preset with the key \"{$data['key']}\" already exists.", 'errors' => ['key' => ['Already used.']]], 422);
        }
        $data['sort_order'] ??= (int) WizardPreset::max('sort_order') + 1;

        $preset = WizardPreset::create($data);
        AuditLogger::log($request->user(), 'wizard_preset.created', WizardPreset::class, $preset->id, $preset->only(['key', 'label']));

        return response()->json(['success' => true, 'message' => "Preset \"{$preset->label}\" added.", 'data' => $preset->load('category:id,name')], 201);
    }

    /** PATCH /ops/wizard-presets/{id} */
    public function updatePreset(Request $request, int $id): JsonResponse
    {
        $preset = WizardPreset::findOrFail($id);
        $data = $this->validatePreset($request, $preset);
        unset($data['key']); // the key is referenced by templates — never renamed

        $before = $preset->toArray();
        $preset->update($data);
        AuditLogger::log($request->user(), 'wizard_preset.updated', WizardPreset::class, $preset->id, [], $before, $preset->fresh()->toArray());

        return response()->json(['success' => true, 'message' => 'Preset saved.', 'data' => $preset->fresh()->load('category:id,name')]);
    }

    /** DELETE /ops/wizard-presets/{id} */
    public function destroyPreset(Request $request, int $id): JsonResponse
    {
        $preset = WizardPreset::findOrFail($id);
        $inUse = TaskTemplate::where('template_key', $preset->key)->exists();

        return $this->removeOrDeactivate($request, $preset, $inUse, 'wizard_preset', $preset->label);
    }

    private function validatePreset(Request $request, ?WizardPreset $preset = null): array
    {
        return $request->validate([
            'label' => 'required|string|min:2|max:100',
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'task_type_key' => ['nullable', 'string', Rule::exists('task_types', 'key')],
            'category_id' => ['nullable', 'integer', Rule::exists('task_categories', 'id')],
            'platform' => 'nullable|string|max:64',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    // ---------------------------------------------------------- shared

    private function removeOrDeactivate(Request $request, $model, bool $inUse, string $auditPrefix, string $name): JsonResponse
    {
        if ($inUse) {
            $model->forceFill(['is_active' => false])->save();
            AuditLogger::log($request->user(), "{$auditPrefix}_deactivated", get_class($model), $model->id, ['name' => $name]);

            return response()->json([
                'success' => true,
                'message' => "\"{$name}\" is used by existing records, so it was switched off instead of deleted. It no longer appears in dropdowns.",
                'data' => ['deleted' => false, 'deactivated' => true],
            ]);
        }

        $id = $model->id;
        $model->delete();
        AuditLogger::log($request->user(), "{$auditPrefix}_deleted", get_class($model), $id, ['name' => $name]);

        return response()->json(['success' => true, 'message' => "\"{$name}\" deleted.", 'data' => ['deleted' => true, 'deactivated' => false]]);
    }
}

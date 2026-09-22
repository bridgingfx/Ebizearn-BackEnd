<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\PlatformSetting;
use App\Models\TaskCategory;
use App\Models\WithdrawalRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2: Super-Admin-controlled platform settings.
 * Countries, task categories, withdrawal rules, fraud rules, platform
 * settings — every change is written to the append-only audit log.
 */
class OpsSettingsController extends Controller
{
    // ------------------------------------------------------------------
    // Countries
    // ------------------------------------------------------------------

    public function countries(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Country::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:4|unique:countries,code',
            'name' => 'required|string|max:128',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $country = Country::create([
            'code' => strtoupper($validator->validated()['code']),
            'name' => $validator->validated()['name'],
            'currency' => strtoupper($validator->validated()['currency'] ?? 'USD'),
            'is_active' => $validator->validated()['is_active'] ?? true,
            'sort_order' => $validator->validated()['sort_order'] ?? 0,
        ]);

        AuditLogger::log($request->user(), 'settings.country_created', Country::class, $country->id, $country->toArray());

        return response()->json(['success' => true, 'data' => $country], 201);
    }

    public function updateCountry(Request $request, string $code): JsonResponse
    {
        $country = Country::where('code', strtoupper($code))->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:128',
            'currency' => 'sometimes|string|size:3',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $before = $country->toArray();
        $data = $validator->validated();
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $country->update($data);

        AuditLogger::log($request->user(), 'settings.country_updated', Country::class, $country->id, [], $before, $country->fresh()->toArray());

        return response()->json(['success' => true, 'data' => $country->fresh()]);
    }

    // ------------------------------------------------------------------
    // Task categories
    // ------------------------------------------------------------------

    public function taskCategories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => TaskCategory::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function storeTaskCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:64|unique:task_categories,slug|regex:/^[a-z0-9-]+$/',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:64',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $category = TaskCategory::create($validator->validated());

        AuditLogger::log($request->user(), 'settings.task_category_created', TaskCategory::class, $category->id, $category->toArray());

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function updateTaskCategory(Request $request, int $id): JsonResponse
    {
        $category = TaskCategory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:64',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $before = $category->toArray();
        $category->update($validator->validated());

        AuditLogger::log($request->user(), 'settings.task_category_updated', TaskCategory::class, $category->id, [], $before, $category->fresh()->toArray());

        return response()->json(['success' => true, 'data' => $category->fresh()]);
    }

    // ------------------------------------------------------------------
    // Withdrawal rules ($10 / $25 / $50 / $100 selectable minimum)
    // ------------------------------------------------------------------

    public function withdrawalRules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'rules' => WithdrawalRule::orderBy('amount_cents')->get(),
                'active_min_cents' => WithdrawalRule::currentMinCents(),
            ],
        ]);
    }

    public function storeWithdrawalRule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount_cents' => 'required|integer|min:100|max:1000000|unique:withdrawal_rules,amount_cents',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $rule = WithdrawalRule::create([
            'amount_cents' => $validator->validated()['amount_cents'],
            'is_active' => WithdrawalRule::count() === 0,
        ]);

        AuditLogger::log($request->user(), 'settings.withdrawal_rule_created', WithdrawalRule::class, $rule->id, $rule->toArray());

        return response()->json(['success' => true, 'data' => $rule], 201);
    }

    public function activateWithdrawalRule(Request $request, int $id): JsonResponse
    {
        $before = WithdrawalRule::currentMinCents();
        $rule = WithdrawalRule::activate($id);

        AuditLogger::log(
            $request->user(),
            'settings.withdrawal_rule_activated',
            WithdrawalRule::class,
            $rule->id,
            ['active_min_cents' => $rule->amount_cents],
            ['active_min_cents' => $before]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'rules' => WithdrawalRule::orderBy('amount_cents')->get(),
                'active_min_cents' => WithdrawalRule::currentMinCents(),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Platform settings (key/value) and fraud rules (group=fraud)
    // ------------------------------------------------------------------

    public function platformSettings(Request $request): JsonResponse
    {
        $query = PlatformSetting::orderBy('group')->orderBy('key');

        if ($request->filled('group')) {
            $query->where('group', $request->input('group'));
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function updatePlatformSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array|min:1|max:100',
            'settings.*.key' => 'required|string|max:128',
            'settings.*.value' => 'nullable|string|max:65535',
            'settings.*.group' => 'nullable|string|max:64',
            'settings.*.is_public' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $changed = [];

        foreach ($validator->validated()['settings'] as $item) {
            $existing = PlatformSetting::where('key', $item['key'])->first();
            $before = $existing?->value;

            PlatformSetting::set(
                $item['key'],
                $item['value'] ?? null,
                $item['group'] ?? $existing?->group ?? 'general',
                $item['is_public'] ?? $existing?->is_public ?? false
            );

            if ($before !== ($item['value'] ?? null)) {
                $changed[$item['key']] = ['before' => $before, 'after' => $item['value'] ?? null];
            }
        }

        if ($changed !== []) {
            AuditLogger::log($request->user(), 'settings.updated', PlatformSetting::class, 0, $changed);
        }

        return response()->json(['success' => true, 'data' => ['changed' => $changed]]);
    }

    // ------------------------------------------------------------------
    // Audit log read (append-only; no update/delete endpoints by design)
    // ------------------------------------------------------------------

    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with('actor:id,name,email,role')->latest('id');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        return response()->json(['success' => true, 'data' => $query->paginate(25)]);
    }

    protected function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }
}

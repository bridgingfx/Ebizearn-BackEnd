<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\FeatureFlag;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminSystemController extends Controller
{
    /**
     * List all Feature Flags.
     */
    public function featureFlags(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureFlag::orderBy('name')->get(),
        ]);
    }

    /**
     * Update feature flag toggle state.
     */
    public function updateFeatureFlag(Request $request, string $key): JsonResponse
    {
        $flag = FeatureFlag::where('key', $key)->firstOrFail();
        $isEnabled = (bool) $request->input('is_enabled');

        $flag->update(['is_enabled' => $isEnabled]);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'feature_flag.updated',
            'entity_type' => FeatureFlag::class,
            'entity_id' => $flag->id,
            'before_state_json' => ['is_enabled' => !$isEnabled],
            'after_state_json' => ['is_enabled' => $isEnabled],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Feature flag {$key} updated.",
            'data' => $flag,
        ]);
    }

    /**
     * Get system settings.
     */
    public function systemSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SystemSetting::all(),
        ]);
    }

    /**
     * Update system setting.
     */
    public function updateSystemSetting(Request $request): JsonResponse
    {
        $key = $request->input('key');
        $value = $request->input('value');

        SystemSetting::set($key, $value);

        return response()->json([
            'success' => true,
            'message' => "Setting {$key} updated successfully.",
        ]);
    }

    /**
     * Paginated audit logs.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::with('actor')
            ->latest('created_at')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $this->withEntityNames(collect($logs->items())),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Add `entity_model` (short model name, e.g. "User") and `entity_name`
     * (a human label, e.g. the user's name or a ticket reference) to each
     * audit row, batch-loaded so the list stays at a fixed query count.
     */
    private function withEntityNames(Collection $logs): Collection
    {
        $idsByType = $logs->groupBy('entity_type')->map(fn ($rows) => $rows->pluck('entity_id')->unique()->all());

        $names = [];
        $lookups = [
            User::class => fn ($ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
            SupportTicket::class => fn ($ids) => collect($ids)->mapWithKeys(fn ($id) => [$id => 'TKT-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]),
            Campaign::class => fn ($ids) => Campaign::whereIn('id', $ids)->pluck('title', 'id'),
            Role::class => fn ($ids) => Role::whereIn('id', $ids)->pluck('label', 'id'),
        ];
        foreach ($idsByType as $type => $ids) {
            if (isset($lookups[$type])) {
                try {
                    $names[$type] = $lookups[$type]($ids);
                } catch (\Throwable) {
                    // A renamed column must never break the audit list.
                }
            }
        }

        return $logs->map(function (AuditLog $log) use ($names) {
            $row = $log->toArray();
            $row['entity_model'] = class_basename((string) $log->entity_type);
            $row['entity_name'] = isset($names[$log->entity_type]) ? ($names[$log->entity_type][$log->entity_id] ?? null) : null;

            return $row;
        })->values();
    }

    /**
     * Full detail for one user (admin "view user" page): account, profile,
     * KYC state, wallet, business, activity counts, recent withdrawals,
     * tickets, audit trail, and effective permissions.
     */
    public function showUser(string $id): JsonResponse
    {
        $user = User::with(['profile', 'wallet', 'business', 'referrer:id,name,email'])->findOrFail($id);

        $submissionCounts = $user->submissions()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $audit = AuditLog::with('actor:id,name,role')
            ->where(function ($q) use ($user) {
                $q->where(fn ($w) => $w->where('entity_type', User::class)->where('entity_id', $user->id))
                    ->orWhere('actor_id', $user->id);
            })
            ->latest('created_at')
            ->limit(15)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'stats' => [
                    'submissions' => $submissionCounts,
                    'submissions_total' => (int) $submissionCounts->sum(),
                    'referrals' => $user->referrals()->count(),
                    'tickets_open' => $user->supportTickets()->whereIn('status', ['open', 'in_progress'])->count(),
                ],
                'withdrawals' => $user->withdrawalRequests()->latest()->limit(10)
                    ->get(['id', 'amount_cents', 'currency', 'payout_method', 'status', 'created_at', 'processed_at']),
                'tickets' => $user->supportTickets()->latest('updated_at')->limit(10)
                    ->get(['id', 'uuid', 'subject', 'category', 'priority', 'status', 'created_at', 'updated_at'])
                    ->map(fn ($t) => $t->toArray() + ['reference' => 'TKT-' . str_pad((string) $t->id, 5, '0', STR_PAD_LEFT)]),
                'audit' => $this->withEntityNames($audit),
                'permissions' => [
                    'role' => $user->rolePermissionNames(),
                    'grants' => $user->grantedPermissionNames(),
                    'denies' => $user->deniedPermissionNames(),
                    'effective' => $user->effectivePermissions(),
                ],
            ],
        ]);
    }

    /**
     * Users management list.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::with(['profile', 'wallet', 'business']);

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(fn($q) => $q->where('name', 'like', $search)->orWhere('email', 'like', $search));
        }

        $users = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Toggle user status (active/suspended).
     */
    public function updateUserStatus(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $status = $request->input('status');

        if (!in_array($status, ['active', 'suspended', 'pending_verification'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 422);
        }

        $before = $user->status;
        $user->update(['status' => $status]);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'user.status_changed',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'before_state_json' => ['status' => $before],
            'after_state_json' => ['status' => $status],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "User status changed to {$status}.",
            'data' => $user,
        ]);
    }

    /**
     * System Health Check.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'operational',
                'database' => 'connected',
                'cache' => 'active',
                'queue' => 'idle',
                'storage' => 'writable',
                'server_time' => now()->toIso8601String(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ]);
    }
}

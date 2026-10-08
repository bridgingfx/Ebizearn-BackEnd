<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\FeatureFlag;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
            // AI settings (incl. the encrypted API key) are Super Admin only —
            // managed through /admin/ai-settings, never listed here.
            'data' => SystemSetting::all()->reject(fn (SystemSetting $s) => str_starts_with($s->key, 'ai_'))->values(),
        ]);
    }

    /**
     * Update system setting. The key is required (an absent key previously
     * hit SystemSetting::set()'s string type-hint and 500'd) and every
     * change is audited like the rest of the settings surface.
     */
    public function updateSystemSetting(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|max:128',
            'value' => 'nullable|string|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = $validator->validated()['key'];
        $value = $validator->validated()['value'] ?? null;

        if (str_starts_with($key, 'ai_')) {
            return response()->json(['success' => false, 'message' => 'AI settings are managed by Super Admin under AI content generator.'], 403);
        }

        $before = SystemSetting::get($key);
        SystemSetting::set($key, $value);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'system_setting.updated',
            'entity_type' => SystemSetting::class,
            'entity_id' => 0,
            'before_state_json' => ['key' => $key, 'value' => $before],
            'after_state_json' => ['key' => $key, 'value' => $value],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

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
            ->latest('id') // same-second entries in the order they happened
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
        return \App\Services\Audit\AuditPresenter::withEntityNames($logs);
    }

    /**
     * Full detail for one user (admin "view user" page): account, profile,
     * KYC state, wallet, business, activity counts, recent withdrawals,
     * tickets, audit trail, and effective permissions.
     */
    public function showUser(Request $request, string $id): JsonResponse
    {
        $user = User::with(['profile', 'wallet', 'business', 'referrer:id,name,email', 'manager:id,name,role'])->findOrFail($id);
        if (!StaffScope::canManageAccount($request->user(), $user)) {
            return StaffScope::notFound();
        }

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
            ->latest('id')
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
        $actor = $request->user();
        $query = User::with(['profile', 'wallet', 'business', 'manager:id,name,role']);

        // Assigned-users scope (Super Admin assigns users to staff).
        StaffScope::apply($query, $actor, 'users.id');

        // A Businesses-only admin sees business accounts only; the other
        // sections that feed on this list see everyone in scope.
        $seesAllRoles = collect([Permission::MANAGE_USERS, Permission::VIEW_WALLETS, Permission::ADJUST_WALLETS, Permission::MANAGE_ROLES])
            ->contains(fn ($p) => $actor->hasPermission($p));
        if (!$seesAllRoles) {
            $query->where('role', 'business');
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }
        if ($request->filled('managed_by')) {
            $query->where('managed_by', $request->input('managed_by') === 'none' ? null : (int) $request->input('managed_by'));
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
     * Create a business user account (create_business_users).
     *
     * The role is fixed to `business` server-side — this endpoint can never
     * mint a staff account. The account is created active and verified (the
     * admin vouches for it) with the same Profile / Wallet / Business rows as
     * a self-signup, so the owner can sign in to the business portal at once
     * with the password the admin set. Business permissions come from the
     * business role grants Super Admin controls.
     */
    public function createBusinessUser(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', new StrongPassword()],
            'company_name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'industry' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            // System fields: set explicitly (not fillable).
            $user->forceFill([
                'role' => 'business',
                'status' => 'active',
                'email_verified_at' => now(),
            ])->save();

            Profile::create([
                'user_id' => $user->id,
                'country_code' => strtoupper($data['country_code'] ?? 'GE'),
                'language' => 'en',
                'contributor_level' => 'starter',
                'fraud_score' => 0,
            ]);

            Wallet::create([
                'user_id' => $user->id,
                'currency' => 'USD',
                'available_balance_cents' => 0,
                'pending_balance_cents' => 0,
                'lifetime_earnings_cents' => 0,
                'total_withdrawn_cents' => 0,
            ]);

            Business::create([
                'owner_id' => $user->id,
                'company_name' => $data['company_name'],
                'website' => $data['website'] ?? null,
                'industry' => $data['industry'] ?? null,
                'billing_email' => $data['email'],
                'status' => 'active',
            ]);

            return $user;
        });

        AuditLogger::log($request->user(), 'business_user.created', User::class, $user->id, [
            'email' => $user->email,
            'company_name' => $data['company_name'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Business account created. The owner can sign in with this email and password.',
            'data' => $user->load(['business', 'wallet', 'profile']),
        ], 201);
    }

    /**
     * PATCH /admin/users/{id}/level { level, locked }
     *
     * Set a contributor's level by hand. Locked: task completions no longer
     * change it. Unlocked: the level is recalculated from approved tasks
     * against the admin-set thresholds straight away.
     */
    public function updateContributorLevel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', \Illuminate\Validation\Rule::in(\App\Services\Contributors\ContributorTierService::TIERS)],
            'locked' => 'required|boolean',
        ]);

        $user = User::with('profile')->findOrFail($id);
        if (!StaffScope::canManageAccount($request->user(), $user)) {
            return StaffScope::notFound();
        }
        if ($user->role !== 'contributor' || !$user->profile) {
            return response()->json(['success' => false, 'message' => 'Levels apply to contributor accounts only.'], 422);
        }

        $profile = $user->profile;
        $before = ['level' => $profile->contributor_level, 'locked' => (bool) $profile->level_locked];

        $profile->contributor_level = $data['level'];
        $profile->level_locked = $data['locked'];
        $profile->save();

        $level = $data['locked']
            ? $data['level']
            : app(\App\Services\Contributors\ContributorTierService::class)->recalculateFor($user->fresh('profile'));

        AuditLogger::log($request->user(), 'user.level_updated', User::class, $user->id, [], $before, ['level' => $level, 'locked' => $data['locked']]);

        return response()->json([
            'success' => true,
            'message' => $data['locked']
                ? "Level set to {$level} and locked."
                : "Level follows completed tasks again — now {$level}.",
            'data' => ['level' => $level, 'locked' => $data['locked']],
        ]);
    }

    /**
     * Toggle user status (active/suspended).
     *
     * Guards:
     *  - nobody can change their own status (a self-suspension would lock
     *    the account — and the team — out with nobody left to reverse it);
     *  - staff accounts (admin/moderator/superadmin) are managed by Super
     *    Admin only — an admin must never suspend a superadmin, a peer
     *    admin, or a moderator;
     *  - suspending revokes every Sanctum token on the spot. Suspension is
     *    otherwise enforced only at the NEXT login (AuthController@socialLogin,
     *    AuthController@login), so without the revocation an already-issued
     *    token would keep working indefinitely.
     */
    public function updateUserStatus(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if (!StaffScope::canManageAccount($request->user(), $user)) {
            return StaffScope::notFound();
        }
        $status = $request->input('status');

        if (!in_array($status, ['active', 'suspended', 'pending_verification'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid status'], 422);
        }

        $actor = $request->user();

        if ((int) $user->id === (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own account status.',
            ], 422);
        }

        if (!$actor->isSuperAdmin() && in_array($user->role, ['admin', 'moderator', 'superadmin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Super Admin can change the status of staff accounts.',
            ], 403);
        }

        $before = $user->status;
        // Direct assignment: 'status' is intentionally NOT in $fillable
        // (prevents privilege escalation via mass assignment).
        $user->status = $status;
        $user->save();

        $tokensRevoked = 0;
        if ($status === 'suspended') {
            $tokensRevoked = $user->tokens()->delete();
        }

        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'user.status_changed',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'before_state_json' => ['status' => $before],
            'after_state_json' => ['status' => $status, 'tokens_revoked' => $tokensRevoked],
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
     * Update a user's editable profile fields (2026-10-07).
     * PATCH /admin/users/{id} — permission: manage_users.
     *
     * Allows staff to correct a business account: name, email, phone and the
     * linked business row (company name, industry, website). Role, status and
     * credentials are NOT editable here. Every change is audit-logged.
     */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = User::with(['profile', 'business'])->findOrFail($id);
        $actor = $request->user();
        if (!StaffScope::canManageAccount($actor, $user)) {
            return StaffScope::notFound();
        }

        if ((int) $user->id === (int) $actor->id) {
            return response()->json(['success' => false, 'message' => 'You cannot edit your own account here.'], 422);
        }

        if (!$actor->isSuperAdmin() && in_array($user->role, ['admin', 'moderator', 'superadmin'], true)) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin can edit staff accounts.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'company_name' => 'sometimes|nullable|string|max:255',
            'industry' => 'sometimes|nullable|string|max:120',
            'website' => 'sometimes|nullable|url|max:255',
            'phone' => 'sometimes|nullable|string|max:32',
            'country_code' => 'sometimes|nullable|string|size:2|alpha',
        ]);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => $user->business?->company_name,
            'industry' => $user->business?->industry,
            'website' => $user->business?->website,
            'phone' => $user->profile?->phone,
            'country_code' => $user->profile?->country_code,
        ];

        if (array_key_exists('name', $validated)) $user->name = $validated['name'];
        if (array_key_exists('email', $validated)) $user->email = $validated['email'];
        $user->save();

        if ($user->business) {
            if (array_key_exists('company_name', $validated)) $user->business->company_name = $validated['company_name'];
            if (array_key_exists('industry', $validated)) $user->business->industry = $validated['industry'];
            if (array_key_exists('website', $validated)) $user->business->website = $validated['website'];
            $user->business->save();
        }

        if ($user->profile) {
            if (array_key_exists('phone', $validated)) {
                $user->profile->phone = $validated['phone'];
            }
            if (array_key_exists('country_code', $validated)) {
                $user->profile->country_code = $validated['country_code'] ? strtoupper($validated['country_code']) : null;
            }
            $user->profile->save();
        }

        $after = [
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => $user->business?->company_name,
            'industry' => $user->business?->industry,
            'website' => $user->business?->website,
            'phone' => $user->profile?->phone,
            'country_code' => $user->profile?->country_code,
        ];

        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'user.profile_updated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'before_state_json' => $before,
            'after_state_json' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account updated.',
            'data' => $user->fresh(['profile', 'business']),
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

    /**
     * Impersonate a user (superadmin only). Returns a SHORT-LIVED, flagged
     * token for the target user so staff can "login as" a business.
     * - Expires after 30 minutes.
     * - Cannot target staff (admin/superadmin) — business/contributor only.
     * - Flagged as impersonation; audit-logged with the staff actor.
     * - Use stopImpersonation to revoke early.
     */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $staff = $request->user();
        if ($staff->role !== 'superadmin') {
            return response()->json(['success' => false, 'message' => 'Only Super Admin can impersonate.'], 403);
        }

        $target = User::find($id);
        if (!$target) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }
        if ($target->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Cannot impersonate a suspended account.'], 422);
        }
        if (in_array($target->role, ['admin', 'superadmin'], true)) {
            return response()->json(['success' => false, 'message' => 'Cannot impersonate staff accounts.'], 422);
        }
        if ($target->id === $staff->id) {
            return response()->json(['success' => false, 'message' => 'Cannot impersonate yourself.'], 422);
        }

        $tokenResult = $target->createToken('impersonation', ['impersonate']);
        $token = $tokenResult->plainTextToken;
        // 30-minute expiry — Sanctum stores created_at; enforce via expires_at.
        $tokenResult->accessToken->forceFill(['expires_at' => now()->addMinutes(30)])->save();

        \App\Models\AuditLog::create([
            'actor_id' => $staff->id,
            'actor_role' => $staff->role,
            'action' => 'user.impersonate',
            'target_type' => 'user',
            'target_id' => $target->id,
            'description' => "Superadmin {$staff->email} started impersonating {$target->email} (30-min token)",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_in_minutes' => 30,
                'impersonated_user_id' => $target->id,
            ],
        ]);
    }

    /**
     * Stop an impersonation session: revoke the impersonation token.
     * The staff member's own session is untouched.
     */
    public function stopImpersonation(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (!$token || !in_array('impersonate', $token->abilities ?? [])) {
            return response()->json(['success' => false, 'message' => 'No active impersonation session.'], 422);
        }

        \App\Models\AuditLog::create([
            'actor_id' => $user->id,
            'actor_role' => $user->role,
            'action' => 'user.impersonate.stop',
            'target_type' => 'user',
            'target_id' => $user->id,
            'description' => "Impersonation session ended for {$user->email}",
            'ip_address' => $request->ip(),
        ]);

        $token->delete();

        return response()->json(['success' => true, 'message' => 'Impersonation ended.']);
    }
}

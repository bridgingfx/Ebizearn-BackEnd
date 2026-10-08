<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Business Team Access: the owner adds team members who log in to the same
 * business panel and work on the owner's business, limited to the sections
 * the owner ticks — and never beyond what the owner holds (User caps a team
 * member's permissions at the owner's, see User::effectivePermissions).
 */
class BusinessTeamController extends Controller
{
    private const MAX_MEMBERS = 20;

    /** GET /business/team */
    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json(['success' => true, 'data' => $this->present($owner)]);
    }

    /** POST /business/team { name, email, password, permissions[] } */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8|max:100',
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(Permission::TEAM_PERMISSIONS)],
        ]);

        abort_if($owner->teamMembers()->count() >= self::MAX_MEMBERS, 422, 'You can add up to ' . self::MAX_MEMBERS . ' team members.');
        $this->assertGrantable($owner, $data['permissions']);

        $member = DB::transaction(function () use ($owner, $data) {
            $member = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'terms_version' => $owner->terms_version,
            ]);
            // System fields: set explicitly (not fillable). The owner vouches
            // for the member, so the account is active and verified.
            $member->forceFill([
                'role' => 'business',
                'status' => 'active',
                'email_verified_at' => now(),
                'business_owner_id' => $owner->id,
                'terms_accepted_at' => now(),
            ])->save();

            Profile::create([
                'user_id' => $member->id,
                'country_code' => $owner->profile?->country_code ?? 'GE',
                'language' => 'en',
                'contributor_level' => 'starter',
                'fraud_score' => 0,
            ]);

            $this->applyPermissions($member, $data['permissions']);

            return $member;
        });

        AuditLogger::log($owner, 'business.team_member_added', User::class, $member->id, [], [], [
            'email' => $member->email,
            'permissions' => $member->fresh()->effectivePermissions(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$member->name} can now sign in with {$member->email}.",
            'data' => $this->present($owner),
        ], 201);
    }

    /** PUT /business/team/{id}/permissions { permissions[] } */
    public function updatePermissions(Request $request, string $id): JsonResponse
    {
        $owner = $this->owner($request);
        $member = $this->member($owner, $id);

        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(Permission::TEAM_PERMISSIONS)],
        ]);

        // Sections the owner no longer holds may stay ticked from before,
        // but nothing new beyond the owner's own access.
        $added = array_diff($data['permissions'], $member->effectivePermissions());
        $this->assertGrantable($owner, $added);

        $before = $member->effectivePermissions();
        $this->applyPermissions($member, $data['permissions']);

        AuditLogger::log($owner, 'business.team_member_permissions', User::class, $member->id, [], ['permissions' => $before], [
            'permissions' => $member->fresh()->effectivePermissions(),
        ]);

        return response()->json(['success' => true, 'message' => 'Access updated.', 'data' => $this->present($owner)]);
    }

    /** PATCH /business/team/{id}/status { status: active|suspended } */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $owner = $this->owner($request);
        $member = $this->member($owner, $id);

        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        $member->forceFill(['status' => $data['status']])->save();
        if ($data['status'] === 'suspended') {
            $member->tokens()->delete();
        }

        AuditLogger::log($owner, 'business.team_member_' . $data['status'], User::class, $member->id);

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'suspended' ? "{$member->name} can no longer sign in." : "{$member->name} can sign in again.",
            'data' => $this->present($owner),
        ]);
    }

    /** DELETE /business/team/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $owner = $this->owner($request);
        $member = $this->member($owner, $id);

        $member->tokens()->delete();
        $member->delete();

        AuditLogger::log($owner, 'business.team_member_removed', User::class, $member->id, [], ['email' => $member->email], []);

        return response()->json(['success' => true, 'message' => "{$member->name} was removed.", 'data' => $this->present($owner)]);
    }

    // ------------------------------------------------------------------

    /** Only the business owner manages the team — never a team member. */
    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_if($user->isTeamMember(), 403, 'Only the business owner can manage the team.');

        return $user;
    }

    private function member(User $owner, string $id): User
    {
        return $owner->teamMembers()->whereKey($id)->firstOrFail();
    }

    /** @param string[] $names */
    private function assertGrantable(User $owner, array $names): void
    {
        $missing = array_values(array_diff($names, $owner->effectivePermissions()));
        if ($missing !== []) {
            $labels = array_map(fn ($p) => Permission::catalog()[$p] ?? $p, $missing);
            abort(response()->json([
                'success' => false,
                'message' => 'You can only give access you have yourself. Not allowed: ' . implode(', ', $labels) . '.',
                'code' => 'permission_not_held',
            ], 403));
        }
    }

    /**
     * Store the ticked sections as overrides on top of the business role:
     * deny the role's team sections that are not ticked, grant ticked ones
     * the role lacks.
     *
     * @param string[] $selected
     */
    private function applyPermissions(User $member, array $selected): void
    {
        $selected = array_values(array_intersect(array_unique($selected), Permission::TEAM_PERMISSIONS));
        $role = array_intersect($member->rolePermissionNames(), Permission::TEAM_PERMISSIONS);

        $member->syncPermissionOverrides(
            array_values(array_diff($selected, $role)),
            array_values(array_diff($role, $selected)),
        );
    }

    private function present(User $owner): array
    {
        $ownerPermissions = $owner->effectivePermissions();
        $catalog = Permission::catalog();

        return [
            'permissions' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => $catalog[$name] ?? $name,
                'available' => in_array($name, $ownerPermissions, true),
            ], Permission::TEAM_PERMISSIONS),
            'max_members' => self::MAX_MEMBERS,
            'members' => $owner->teamMembers()->orderBy('name')->get()->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'status' => $m->status,
                'created_at' => $m->created_at,
                'permissions' => $m->setRelation('teamOwner', $owner)->effectivePermissions(),
            ])->values(),
        ];
    }
}

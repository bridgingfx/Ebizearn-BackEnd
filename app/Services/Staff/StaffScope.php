<?php

namespace App\Services\Staff;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which users a staff account may see and act on.
 *
 * Super Admin assigns contributors / businesses / moderators to an admin or
 * moderator (users.managed_by). A staff account with at least one assigned
 * user is SCOPED: every admin list and action is limited to those users and
 * their data (KYC, withdrawals, deposits, wallets, tickets, the campaigns
 * and tasks of their businesses). Super Admin, and staff with no
 * assignments, are unrestricted.
 */
class StaffScope
{
    /**
     * Assigned user ids, or null when the actor is unrestricted.
     * Memoised on the current request (never across requests).
     *
     * @return array<int>|null
     */
    public static function userIds(?User $actor): ?array
    {
        if (!$actor || $actor->isSuperAdmin() || !in_array($actor->role, ['admin', 'moderator'], true)) {
            return null;
        }

        $key = 'staff_scope.' . $actor->id;
        $attributes = request()->attributes;
        if (!$attributes->has($key)) {
            $ids = User::where('managed_by', $actor->id)->pluck('id')->all();
            $attributes->set($key, $ids === [] ? null : array_map('intval', $ids));
        }

        return $attributes->get($key);
    }

    public static function isScoped(?User $actor): bool
    {
        return self::userIds($actor) !== null;
    }

    /** Can the actor see / act on data owned by this user id? */
    public static function allowsUser(?User $actor, ?int $userId): bool
    {
        $ids = self::userIds($actor);

        return $ids === null || ($userId !== null && in_array((int) $userId, $ids, true));
    }

    /** Limit a query to rows whose $column holds an assigned user id. */
    public static function apply(Builder|QueryBuilder $query, ?User $actor, string $column = 'user_id'): Builder|QueryBuilder
    {
        $ids = self::userIds($actor);
        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }

        return $query;
    }

    /** Limit a campaigns query to campaigns of assigned businesses. */
    public static function applyToCampaigns(Builder|QueryBuilder $query, ?User $actor, string $businessColumn = 'business_id'): Builder|QueryBuilder
    {
        $ids = self::userIds($actor);
        if ($ids !== null) {
            $query->whereIn($businessColumn, DB::table('businesses')->whereIn('owner_id', $ids)->select('id'));
        }

        return $query;
    }

    /** Limit a tasks query to tasks of campaigns of assigned businesses. */
    public static function applyToTasks(Builder|QueryBuilder $query, ?User $actor, string $campaignColumn = 'campaign_id'): Builder|QueryBuilder
    {
        $ids = self::userIds($actor);
        if ($ids !== null) {
            $query->whereIn($campaignColumn, DB::table('campaigns')
                ->whereIn('business_id', DB::table('businesses')->whereIn('owner_id', $ids)->select('id'))
                ->select('id'));
        }

        return $query;
    }

    /** Is this business (by id) owned by an assigned user? */
    public static function allowsBusiness(?User $actor, ?int $businessId): bool
    {
        if (self::userIds($actor) === null) {
            return true;
        }
        $ownerId = $businessId ? DB::table('businesses')->where('id', $businessId)->value('owner_id') : null;

        return self::allowsUser($actor, $ownerId ? (int) $ownerId : null);
    }

    /**
     * Account-management access to a specific user: within scope AND the
     * right section permission — business accounts need manage_businesses
     * or manage_users; every other account needs manage_users.
     */
    public static function canManageAccount(?User $actor, User $target): bool
    {
        if (!$actor || !self::allowsUser($actor, $target->id)) {
            return false;
        }
        if ($actor->hasPermission(Permission::MANAGE_USERS)) {
            return true;
        }

        return $target->role === 'business' && $actor->hasPermission(Permission::MANAGE_BUSINESSES);
    }

    /** Standard 404 body for out-of-scope records (does not leak existence). */
    public static function notFound(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not found.'], 404);
    }
}

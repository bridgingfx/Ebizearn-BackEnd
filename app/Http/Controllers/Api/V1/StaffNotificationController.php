<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditPresenter;
use App\Services\Staff\StaffScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin / Super Admin notification bell: everything happening on the
 * platform — sign-ups, KYC, tasks and proofs, campaigns, deposits and
 * withdrawals, tickets, and changes made by staff — built from the audit
 * trail. Unread = newer than the staff member's staff_notifications_seen_at.
 * An admin with assigned users only gets activity about those users.
 */
class StaffNotificationController extends Controller
{
    /** Filter chips → audit action prefixes. */
    private const CATEGORIES = [
        'users' => ['user.registered', 'user.team_member_joined', 'business_user.', 'business.team_member', 'user.status_changed', 'user.profile_updated', 'user.level_updated'],
        'kyc' => ['kyc.', 'profile.country_change', 'country_change.', 'social_channel.'],
        'tasks' => ['task.', 'submission.', 'task_template.', 'task_type.'],
        'campaigns' => ['campaign.'],
        'money' => ['withdrawal.', 'deposit.', 'wallet.', 'payout.'],
        'support' => ['support_ticket.'],
        'staff' => ['role.', 'staff.', 'settings.', 'system_setting.', 'feature_flag.', 'user.permissions', 'superadmin.', 'user.impersonate', 'deposit_method.'],
    ];

    /** Machine noise that is not worth a notification. */
    private const IGNORED = ['submission.screened', 'kyc.sumsub_webhook', 'user.password_changed'];

    /** GET /admin/notifications?category=&page= */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = $this->feed($actor);

        $category = $request->input('category');
        if ($category && isset(self::CATEGORIES[$category])) {
            $query->where(function (Builder $q) use ($category) {
                foreach (self::CATEGORIES[$category] as $prefix) {
                    $q->orWhere('action', 'like', $prefix . '%');
                }
            });
        }

        $page = $query->with('actor:id,name,role')->latest('created_at')->latest('id')->paginate(30);

        return response()->json([
            'success' => true,
            'data' => AuditPresenter::withEntityNames(collect($page->items())),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $this->unreadCount($actor),
                'seen_at' => $actor->staff_notifications_seen_at,
            ],
        ]);
    }

    /** GET /admin/notifications/unread-count — polled by the bell. */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['unread' => $this->unreadCount($request->user())]]);
    }

    /** POST /admin/notifications/seen — opening the bell marks everything read. */
    public function markSeen(Request $request): JsonResponse
    {
        $actor = $request->user();
        $actor->forceFill(['staff_notifications_seen_at' => now()])->save();

        return response()->json(['success' => true, 'data' => ['unread' => 0]]);
    }

    // ------------------------------------------------------------------

    private function feed(User $actor): Builder
    {
        $query = AuditLog::query()
            ->whereNotIn('action', self::IGNORED)
            // Your own actions are not news to you.
            ->where(fn ($q) => $q->whereNull('actor_id')->orWhere('actor_id', '!=', $actor->id));

        if (($ids = StaffScope::userIds($actor)) !== null) {
            $query->where(function ($q) use ($ids) {
                $q->whereIn('actor_id', $ids)
                    ->orWhere(fn ($w) => $w->where('entity_type', User::class)->whereIn('entity_id', $ids));
            });
        }

        return $query;
    }

    private function unreadCount(User $actor): int
    {
        // First visit: count the last 7 days, not the whole history.
        $since = $actor->staff_notifications_seen_at ?? now()->subDays(7);

        return min(999, $this->feed($actor)->where('created_at', '>', $since)->count());
    }
}

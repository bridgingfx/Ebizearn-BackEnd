<?php

namespace App\Services\Social;

use App\Models\Business;
use App\Models\BusinessTaskAlert;
use App\Models\Campaign;
use App\Models\SocialChannel;
use App\Models\Task;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The business profile shown on the task page (and to staff): real counts
 * instead of mock numbers. A business is followed through its owner account.
 *
 * - posts: tasks the business has created (all its campaigns)
 * - followers / following: user_follows rows on the owner account
 * - images: campaign post images, newest first
 */
class BusinessProfileService
{
    public const IMAGE_LIMIT = 9;

    public function find(string $id): Business
    {
        return Business::whereKeyOrUuid($id)->firstOrFail();
    }

    /** The business a business-role user works for (owner or team member). */
    public function forUser(User $user): ?Business
    {
        return $user->business ?? ($user->business_owner_id ? Business::where('owner_id', $user->business_owner_id)->first() : null);
    }

    public function stats(Business $business): array
    {
        return [
            'posts' => Task::whereHas('campaign', fn ($q) => $q->where('business_id', $business->id))->count(),
            'followers' => UserFollow::where('following_id', $business->owner_id)->count(),
            'following' => UserFollow::where('follower_id', $business->owner_id)->count(),
        ];
    }

    public function profile(Business $business, ?User $viewer = null): array
    {
        $business->loadMissing('owner.profile');

        $images = Campaign::where('business_id', $business->id)
            ->whereNotNull('content_image_path')
            ->latest('created_at')->latest('id')
            ->limit(self::IMAGE_LIMIT)
            ->get(['id', 'uuid', 'title', 'content_image_path', 'created_at'])
            ->map(fn (Campaign $c) => [
                'campaign_uuid' => $c->uuid,
                'title' => $c->title,
                'url' => $c->content_image_url,
                'created_at' => $c->created_at,
            ])->values();

        $logoPath = Campaign::where('business_id', $business->id)->whereNotNull('logo_path')->latest('id')->value('logo_path');

        return [
            'id' => $business->id,
            'uuid' => $business->uuid,
            'name' => $business->company_name,
            'handle' => Str::slug($business->company_name, '_') ?: 'business',
            'industry' => $business->industry,
            'website' => $business->website,
            'verified' => $business->verified_at !== null,
            'avatar_url' => $business->owner?->profile?->avatar_url
                ?: ($logoPath ? Storage::disk('public')->url($logoPath) : null),
            'stats' => $this->stats($business),
            'images' => $images,
            'socials' => SocialChannel::where('user_id', $business->owner_id)->where('status', 'verified')
                ->orderBy('platform')->get(['platform', 'handle', 'profile_url', 'followers']),
            'viewer' => $viewer ? [
                'is_following' => UserFollow::where('follower_id', $viewer->id)->where('following_id', $business->owner_id)->exists(),
                'alerts_on' => BusinessTaskAlert::where('user_id', $viewer->id)->where('business_id', $business->id)->exists(),
                'follows_you' => UserFollow::where('follower_id', $business->owner_id)->where('following_id', $viewer->id)->exists(),
            ] : null,
        ];
    }

    /**
     * Followers or following of a user, with "does the user follow them back".
     * Emails only for staff — a business sees names, not contact details.
     *
     * @param 'followers'|'following' $type
     */
    public function people(User $user, string $type, int $perPage = 20, ?string $search = null, bool $withEmail = false): LengthAwarePaginator
    {
        $followers = $type === 'followers';
        $otherColumn = $followers ? 'follower_id' : 'following_id';

        $page = UserFollow::query()
            ->where($followers ? 'following_id' : 'follower_id', $user->id)
            ->with([($followers ? 'follower' : 'following') . ':id,uuid,name,email,role,status,created_at', ($followers ? 'follower' : 'following') . '.profile:id,user_id,country_code,avatar_url'])
            ->when($search, fn ($q) => $q->whereHas($followers ? 'follower' : 'following', fn ($u) => $u
                ->where('name', 'like', '%' . $search . '%')->orWhere('email', 'like', '%' . $search . '%')))
            ->latest('id')
            ->paginate(max(1, min(100, $perPage)));

        $otherIds = $page->getCollection()->pluck($otherColumn)->all();
        // The reverse direction, to show "follows you" / "follow back".
        $reverse = UserFollow::query()
            ->where($followers ? 'follower_id' : 'following_id', $user->id)
            ->whereIn($followers ? 'following_id' : 'follower_id', $otherIds)
            ->pluck($followers ? 'following_id' : 'follower_id')
            ->flip();

        $page->setCollection($page->getCollection()->map(function (UserFollow $f) use ($followers, $otherColumn, $reverse, $withEmail) {
            $person = $followers ? $f->follower : $f->following;

            return [
                'id' => $person?->id,
                'uuid' => $person?->uuid,
                'name' => $person?->name,
                'email' => $withEmail ? $person?->email : null,
                'role' => $person?->role,
                'country_code' => $person?->profile?->country_code,
                'avatar_url' => $person?->profile?->avatar_url,
                'followed_at' => $f->created_at,
                'mutual' => $reverse->has($f->{$otherColumn}),
            ];
        }));

        return $page;
    }
}

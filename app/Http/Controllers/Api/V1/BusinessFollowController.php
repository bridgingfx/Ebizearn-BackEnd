<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessTaskAlert;
use App\Models\User;
use App\Models\UserFollow;
use App\Notifications\FollowedBack;
use App\Notifications\NewFollower;
use App\Services\Social\BusinessProfileService;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business profiles: contributors follow a business and turn on its bell
 * (new-task alerts); the business sees its followers and follows back;
 * staff see the counts and lists on the user page.
 */
class BusinessFollowController extends Controller
{
    public function __construct(private BusinessProfileService $profiles)
    {
    }

    /** GET /businesses/{id}/profile */
    public function show(Request $request, string $id): JsonResponse
    {
        $business = $this->profiles->find($id);

        return $this->ok($this->profiles->profile($business, $request->user()));
    }

    /** POST /businesses/{id}/follow */
    public function follow(Request $request, string $id): JsonResponse
    {
        $business = $this->profiles->find($id);
        $user = $request->user();
        abort_if($business->owner_id === $user->id, 422, 'You cannot follow your own business.');

        $follow = UserFollow::firstOrCreate(['follower_id' => $user->id, 'following_id' => $business->owner_id]);
        if ($follow->wasRecentlyCreated && $business->owner) {
            $business->owner->notify(new NewFollower($user->loadMissing('profile')));
        }

        return $this->ok($this->profiles->profile($business, $user), 'You are now following ' . $business->company_name . '.');
    }

    /** DELETE /businesses/{id}/follow — unfollowing also turns the bell off. */
    public function unfollow(Request $request, string $id): JsonResponse
    {
        $business = $this->profiles->find($id);
        $user = $request->user();

        UserFollow::where('follower_id', $user->id)->where('following_id', $business->owner_id)->delete();
        BusinessTaskAlert::where('user_id', $user->id)->where('business_id', $business->id)->delete();

        return $this->ok($this->profiles->profile($business, $user), 'Unfollowed ' . $business->company_name . '.');
    }

    /** POST /businesses/{id}/alerts { enabled: bool } — the bell. */
    public function alerts(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $business = $this->profiles->find($id);
        $user = $request->user();

        if ($data['enabled']) {
            BusinessTaskAlert::firstOrCreate(['user_id' => $user->id, 'business_id' => $business->id]);
        } else {
            BusinessTaskAlert::where('user_id', $user->id)->where('business_id', $business->id)->delete();
        }

        return $this->ok(
            $this->profiles->profile($business, $user),
            $data['enabled'] ? 'Notifications on — we will tell you when ' . $business->company_name . ' posts a task.' : 'Notifications off.'
        );
    }

    // ------------------------------------------------------------------
    // Business (own account)
    // ------------------------------------------------------------------

    /** GET /business/profile */
    public function own(Request $request): JsonResponse
    {
        $business = $this->profiles->forUser($request->user());
        abort_unless($business, 404, 'Business profile not found.');

        return $this->ok($this->profiles->profile($business));
    }

    /** GET /business/followers | /business/following */
    public function ownPeople(Request $request, string $type): JsonResponse
    {
        $business = $this->profiles->forUser($request->user());
        abort_unless($business && $business->owner, 404, 'Business profile not found.');

        return $this->paginated($this->profiles->people($business->owner, $type, (int) $request->input('per_page', 20), $request->input('search')));
    }

    /** POST /business/following/{userId} — follow a follower back. */
    public function followBack(Request $request, int $userId): JsonResponse
    {
        $business = $this->profiles->forUser($request->user());
        abort_unless($business, 404, 'Business profile not found.');
        abort_if($userId === $business->owner_id, 422, 'You cannot follow yourself.');

        // Only people who follow the business can be followed back.
        abort_unless(UserFollow::where('follower_id', $userId)->where('following_id', $business->owner_id)->exists(), 422, 'This user does not follow you.');

        $follow = UserFollow::firstOrCreate(['follower_id' => $business->owner_id, 'following_id' => $userId]);
        if ($follow->wasRecentlyCreated) {
            User::find($userId)?->notify(new FollowedBack($business));
        }

        return $this->ok(['stats' => $this->profiles->stats($business)], 'Followed back.');
    }

    /** DELETE /business/following/{userId} */
    public function unfollowUser(Request $request, int $userId): JsonResponse
    {
        $business = $this->profiles->forUser($request->user());
        abort_unless($business, 404, 'Business profile not found.');

        UserFollow::where('follower_id', $business->owner_id)->where('following_id', $userId)->delete();

        return $this->ok(['stats' => $this->profiles->stats($business)], 'Unfollowed.');
    }

    // ------------------------------------------------------------------
    // Staff
    // ------------------------------------------------------------------

    /** GET /admin/users/{id}/follows?type=followers|following */
    public function staffPeople(Request $request, int $id): JsonResponse
    {
        $type = $request->input('type') === 'following' ? 'following' : 'followers';
        $user = User::findOrFail($id);
        if (!StaffScope::canManageAccount($request->user(), $user)) {
            return StaffScope::notFound();
        }

        return $this->paginated($this->profiles->people($user, $type, (int) $request->input('per_page', 20), $request->input('search'), true));
    }

    private function ok(mixed $data, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['success' => true, 'message' => $message], fn ($v) => $v !== null) + ['data' => $data]);
    }

    private function paginated($page): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}

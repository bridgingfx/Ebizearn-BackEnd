<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialChannel;
use App\Services\Audit\AuditLogger;
use App\Services\Social\SocialChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contributor social channels (profile → Connected Social Accounts) and the
 * staff review queue. Ownership is proven with a code placed in the bio.
 */
class SocialChannelController extends Controller
{
    public function __construct(private SocialChannelService $channels)
    {
    }

    // ------------------------------------------------------------------
    // Contributor
    // ------------------------------------------------------------------

    /** GET /social-channels */
    public function index(Request $request): JsonResponse
    {
        return $this->ok($request->user()->socialChannels()->orderBy('id')->get());
    }

    /**
     * POST /social-channels { platform, profile_url, followers? }
     * Adds (or replaces) the channel for that platform and issues a bio code.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(SocialChannel::PLATFORMS)],
            'profile_url' => 'required|string|max:500',
            'followers' => 'nullable|integer|min:0|max:2000000000',
        ], [
            'profile_url.required' => 'Paste your profile link or username.',
        ]);

        $user = $request->user();
        ['handle' => $handle, 'profile_url' => $url] = $this->channels->normalize($data['platform'], $data['profile_url']);

        $claimedElsewhere = SocialChannel::where('platform', $data['platform'])
            ->whereRaw('LOWER(handle) = ?', [strtolower($handle)])
            ->where('user_id', '!=', $user->id)
            ->whereIn('status', ['pending', 'verified'])
            ->exists();
        abort_if($claimedElsewhere, 422, 'This ' . $this->channels->label($data['platform']) . ' account is already linked to another eBizEarn account. Contact support if it is yours.');

        $existing = $user->socialChannels()->where('platform', $data['platform'])->first();
        $sameHandle = $existing && strtolower($existing->handle) === strtolower($handle);

        $channel = $user->socialChannels()->updateOrCreate(['platform' => $data['platform']], [
            'handle' => $handle,
            'profile_url' => $url,
            'followers' => $data['followers'] ?? null,
            // Keep the verified state and code when only the follower count changed.
            'verification_code' => $sameHandle ? $existing->verification_code : $this->channels->newCode(),
            'status' => $sameHandle && $existing->status === 'verified' ? 'verified' : 'unverified',
            'rejection_reason' => null,
            'submitted_at' => $sameHandle && $existing->status === 'verified' ? $existing->submitted_at : null,
            'verified_at' => $sameHandle ? $existing->verified_at : null,
            'reviewed_by' => $sameHandle ? $existing->reviewed_by : null,
        ]);

        AuditLogger::log($user, 'social_channel.saved', SocialChannel::class, $channel->id, ['platform' => $channel->platform, 'handle' => $handle]);

        return $this->ok($channel->fresh(), 'Channel saved. Add the code to your bio, then submit it for review.', 201);
    }

    /** POST /social-channels/{id}/submit — the code is in the bio, ask staff to check. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $channel = $request->user()->socialChannels()->findOrFail($id);
        abort_unless(in_array($channel->status, ['unverified', 'rejected'], true), 422, 'This channel is already ' . $channel->status . '.');

        $channel->update(['status' => 'pending', 'submitted_at' => now(), 'rejection_reason' => null]);
        AuditLogger::log($request->user(), 'social_channel.submitted', SocialChannel::class, $channel->id, ['platform' => $channel->platform]);

        return $this->ok($channel->fresh(), 'Submitted. We will verify your channel shortly.');
    }

    /** DELETE /social-channels/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $channel = $request->user()->socialChannels()->findOrFail($id);
        AuditLogger::log($request->user(), 'social_channel.removed', SocialChannel::class, $channel->id, ['platform' => $channel->platform, 'handle' => $channel->handle]);
        $channel->delete();

        return $this->ok(null, 'Channel removed.');
    }

    // ------------------------------------------------------------------
    // Staff (review_kyc)
    // ------------------------------------------------------------------

    /** GET /staff/social-channels?status=pending|verified|rejected|all&search= */
    public function staffIndex(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $query = SocialChannel::query()->with('user:id,uuid,name,email,role,status');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('handle', 'like', $search)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $search)->orWhere('email', 'like', $search)));
        }

        $page = $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'pending' => SocialChannel::where('status', 'pending')->count(),
            ],
        ]);
    }

    /** POST /staff/social-channels/{id}/decision { decision: approve|reject, reason?, followers? } */
    public function decision(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:500',
            'followers' => 'nullable|integer|min:0|max:2000000000',
        ], [
            'reason.required_if' => 'Tell the contributor why the channel was rejected.',
        ]);

        $channel = SocialChannel::findOrFail($id);
        abort_unless($channel->status === 'pending', 422, 'This channel is not awaiting review.');

        $approve = $data['decision'] === 'approve';
        $before = ['status' => $channel->status];

        $channel->update([
            'status' => $approve ? 'verified' : 'rejected',
            'verified_at' => $approve ? now() : null,
            'reviewed_by' => $request->user()->id,
            'rejection_reason' => $approve ? null : $data['reason'],
            'followers' => array_key_exists('followers', $data) && $data['followers'] !== null ? $data['followers'] : $channel->followers,
        ]);

        AuditLogger::log($request->user(), $approve ? 'social_channel.approved' : 'social_channel.rejected', SocialChannel::class, $channel->id,
            ['platform' => $channel->platform, 'handle' => $channel->handle], $before, ['status' => $channel->status, 'reason' => $channel->rejection_reason]);

        return $this->ok($channel->fresh()->load('user:id,uuid,name,email,role,status'), $approve ? 'Channel verified.' : 'Channel rejected.');
    }

    private function ok($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}

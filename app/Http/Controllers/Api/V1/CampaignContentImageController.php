<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The image contributors post together with the campaign's post text.
 *
 * Business (own campaigns): a new or removed image after approval sends
 * the post content back to staff review. Staff (edit_campaigns): the
 * change is approved by them.
 */
class CampaignContentImageController extends Controller
{
    private const RULES = ['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120'];
    private const MESSAGES = [
        'image.max' => 'The image must be 5 MB or smaller.',
        'image.mimes' => 'Upload a JPG, PNG or WebP image.',
        'image.image' => 'Upload a JPG, PNG or WebP image.',
    ];

    /** POST /business/campaigns/{id}/content-image (multipart: image) */
    public function businessUpload(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($id);
        Gate::authorize('update', $campaign);

        return $this->store($request, $campaign, staff: false);
    }

    /** DELETE /business/campaigns/{id}/content-image */
    public function businessRemove(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($id);
        Gate::authorize('update', $campaign);

        return $this->remove($request, $campaign, staff: false);
    }

    /** POST /staff/campaigns/{id}/content-image */
    public function staffUpload(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($id);
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }

        return $this->store($request, $campaign, staff: true);
    }

    /** DELETE /staff/campaigns/{id}/content-image */
    public function staffRemove(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($id);
        if (!StaffScope::allowsBusiness($request->user(), $campaign->business_id)) {
            return StaffScope::notFound();
        }

        return $this->remove($request, $campaign, staff: true);
    }

    // ------------------------------------------------------------------

    private function find(string $id): Campaign
    {
        return Campaign::where(fn ($q) => $q->where('id', $id)->orWhere('uuid', $id))->firstOrFail();
    }

    private function store(Request $request, Campaign $campaign, bool $staff): JsonResponse
    {
        $request->validate(self::RULES, self::MESSAGES);

        $old = $campaign->getAttributes()['content_image_path'] ?? null;
        $path = $request->file('image')->store('campaign-content', 'public');

        $campaign->forceFill(['content_image_path' => $path] + $this->reviewState($campaign, $staff, $request))->save();
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        AuditLogger::log($request->user(), 'campaign.content_image_uploaded', Campaign::class, $campaign->id);

        return $this->ok($campaign, 'Image uploaded.');
    }

    private function remove(Request $request, Campaign $campaign, bool $staff): JsonResponse
    {
        $old = $campaign->getAttributes()['content_image_path'] ?? null;
        if ($old) {
            $campaign->forceFill(['content_image_path' => null] + $this->reviewState($campaign, $staff, $request))->save();
            Storage::disk('public')->delete($old);
            AuditLogger::log($request->user(), 'campaign.content_image_removed', Campaign::class, $campaign->id);
        }

        return $this->ok($campaign, 'Image removed.');
    }

    /** A business change to approved content goes back to review; staff changes are approved. */
    private function reviewState(Campaign $campaign, bool $staff, Request $request): array
    {
        if ($staff) {
            return $campaign->content_mode
                ? ['content_status' => 'approved', 'content_reviewed_by' => $request->user()->id, 'content_reviewed_at' => now()]
                : [];
        }

        return $campaign->content_mode && $campaign->content_status === 'approved'
            ? ['content_status' => 'pending', 'content_reviewed_by' => null, 'content_reviewed_at' => null]
            : [];
    }

    private function ok(Campaign $campaign, string $message): JsonResponse
    {
        $campaign->refresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'content_image_url' => $campaign->content_image_url,
                'content_status' => $campaign->content_status,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignMedia;
use App\Services\Audit\AuditLogger;
use App\Services\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Staff (edit_campaigns): photos and videos on a campaign. Contributors see
 * them on the task page and can download them to post.
 */
class CampaignMediaController extends Controller
{
    public const MAX_ITEMS = 12;
    private const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_MIMES = ['mp4', 'webm', 'mov', 'm4v'];

    /** GET /staff/campaigns/{id}/media */
    public function index(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($request, $id);

        return response()->json(['success' => true, 'data' => $campaign->media()->get()]);
    }

    /**
     * POST /staff/campaigns/{id}/media (multipart: files[])
     * Images up to 10 MB, videos up to 100 MB; at most 12 items per campaign.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $campaign = $this->find($request, $id);

        $request->validate([
            'files' => 'required|array|min:1|max:' . self::MAX_ITEMS,
            'files.*' => 'required|file|mimes:' . implode(',', array_merge(self::IMAGE_MIMES, self::VIDEO_MIMES)) . '|max:102400',
        ], [
            'files.required' => 'Choose at least one photo or video.',
            'files.*.mimes' => 'Upload JPG, PNG, WebP or GIF photos, or MP4, WebM or MOV videos.',
            'files.*.max' => 'Each file must be 100 MB or smaller.',
        ]);

        $existing = $campaign->media()->count();
        abort_if($existing + count($request->file('files')) > self::MAX_ITEMS, 422, 'A campaign can have up to ' . self::MAX_ITEMS . ' photos and videos.');

        $order = (int) $campaign->media()->max('sort_order');
        $created = [];
        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $isVideo = in_array($ext, self::VIDEO_MIMES, true) || str_starts_with((string) $file->getMimeType(), 'video/');
            abort_if(!$isVideo && $file->getSize() > 10 * 1024 * 1024, 422, 'Photos must be 10 MB or smaller.');

            $created[] = CampaignMedia::create([
                'campaign_id' => $campaign->id,
                'type' => $isVideo ? 'video' : 'image',
                'path' => $file->store('campaign-media/' . $campaign->id, 'public'),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'sort_order' => ++$order,
                'uploaded_by' => $request->user()->id,
            ]);
        }

        AuditLogger::log($request->user(), 'campaign.media_uploaded', Campaign::class, $campaign->id, ['count' => count($created)]);

        return response()->json([
            'success' => true,
            'message' => count($created) === 1 ? 'File uploaded.' : count($created) . ' files uploaded.',
            'data' => $campaign->media()->get(),
        ], 201);
    }

    /** DELETE /staff/campaigns/{id}/media/{mediaId} */
    public function destroy(Request $request, string $id, int $mediaId): JsonResponse
    {
        $campaign = $this->find($request, $id);
        $media = $campaign->media()->whereKey($mediaId)->firstOrFail();

        Storage::disk('public')->delete($media->getAttributes()['path']);
        $media->delete();
        AuditLogger::log($request->user(), 'campaign.media_removed', Campaign::class, $campaign->id, ['media_id' => $mediaId]);

        return response()->json(['success' => true, 'message' => 'File removed.', 'data' => $campaign->media()->get()]);
    }

    private function find(Request $request, string $id): Campaign
    {
        $campaign = Campaign::whereKeyOrUuid($id)->firstOrFail();
        abort_unless(StaffScope::allowsBusiness($request->user(), $campaign->business_id), 404);

        return $campaign;
    }
}

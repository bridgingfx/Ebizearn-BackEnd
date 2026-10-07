<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPlatform;
use Illuminate\Http\JsonResponse;

/**
 * Public social-platform catalog (additive 2026-10-07).
 *
 * Returns the active platforms Super Admin configured — name, logo and
 * brand color — so every picker (admin, moderator, business) stays in
 * sync without a code change.
 */
class SocialPlatformController extends Controller
{
    public function index(): JsonResponse
    {
        $platforms = SocialPlatform::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SocialPlatform $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'logo_url' => $p->logoUrl(),
                'brand_color' => $p->brand_color,
            ]);

        return response()->json(['success' => true, 'data' => $platforms]);
    }
}

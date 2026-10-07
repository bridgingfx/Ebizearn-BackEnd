<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SocialPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Super-Admin management of social platforms (additive 2026-10-07).
 *
 * Super Admin can add a new social media (name + SVG/PNG logo upload),
 * edit, deactivate or delete it. The active set is served publicly at
 * GET /platforms and drives the platform picker for admin, moderator and
 * business users — no code change needed for a new network.
 */
class OpsSocialPlatformController extends Controller
{
    /** GET /admin/ops/platforms — every platform, active or not. */
    public function index(): JsonResponse
    {
        $platforms = SocialPlatform::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SocialPlatform $p) => $this->serialize($p));

        return response()->json(['success' => true, 'data' => $platforms]);
    }

    /** POST /admin/ops/platforms { name, logo?, brand_color?, sort_order? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'logo' => 'nullable|file|mimes:svg,png|max:1024',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);

        $key = Str::slug($data['name'], '_');
        if (SocialPlatform::where('key', $key)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A platform with a similar name already exists.',
            ], 422);
        }

        $platform = SocialPlatform::create([
            'key' => $key,
            'name' => trim($data['name']),
            'logo_path' => $this->storeLogo($request, $key),
            'brand_color' => $data['brand_color'] ?? null,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->audit($request, 'social_platform.created', $platform);

        return response()->json([
            'success' => true,
            'message' => $platform->name . ' is now available in the platform picker.',
            'data' => $this->serialize($platform),
        ], 201);
    }

    /** PATCH /admin/ops/platforms/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $platform = SocialPlatform::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'logo' => 'nullable|file|mimes:svg,png|max:1024',
            'remove_logo' => 'sometimes|boolean',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => 'sometimes|required|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:1000',
        ]);

        $before = $platform->only(['name', 'brand_color', 'is_active', 'sort_order']);

        if (isset($data['name'])) {
            $platform->name = trim($data['name']);
        }
        if (!empty($data['remove_logo']) || $request->hasFile('logo')) {
            $this->deleteLogo($platform);
            $platform->logo_path = null;
        }
        if ($request->hasFile('logo')) {
            $platform->logo_path = $this->storeLogo($request, $platform->key);
        }
        foreach (['brand_color', 'is_active', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $platform->{$field} = $data[$field];
            }
        }
        $platform->save();

        $this->audit($request, 'social_platform.updated', $platform, $before);

        return response()->json(['success' => true, 'data' => $this->serialize($platform->fresh())]);
    }

    /** DELETE /admin/ops/platforms/{id} — built-ins can only be deactivated. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $platform = SocialPlatform::findOrFail($id);

        if (in_array($platform->key, SocialPlatform::BUILTIN_KEYS, true)) {
            $platform->update(['is_active' => false]);
            $this->audit($request, 'social_platform.deactivated', $platform);

            return response()->json([
                'success' => true,
                'message' => $platform->name . ' is a built-in platform — it was deactivated instead of deleted.',
                'data' => $this->serialize($platform->fresh()),
            ]);
        }

        $this->deleteLogo($platform);
        $this->audit($request, 'social_platform.deleted', $platform);
        $platform->delete();

        return response()->json(['success' => true, 'message' => 'Platform deleted.']);
    }

    // ------------------------------------------------------------------

    protected function serialize(SocialPlatform $p): array
    {
        return [
            'id' => $p->id,
            'key' => $p->key,
            'name' => $p->name,
            'logo_url' => $p->logoUrl(),
            'brand_color' => $p->brand_color,
            'is_active' => (bool) $p->is_active,
            'sort_order' => (int) $p->sort_order,
            'builtin' => in_array($p->key, SocialPlatform::BUILTIN_KEYS, true),
        ];
    }

    /**
     * Store an SVG/PNG logo on the public disk. SVGs are sanitized:
     * script tags and event-handler attributes are stripped.
     */
    protected function storeLogo(Request $request, string $key): ?string
    {
        if (!$request->hasFile('logo')) {
            return null;
        }

        $file = $request->file('logo');
        $ext = strtolower($file->getClientOriginalExtension());
        $filename = 'platform-' . $key . '-' . time() . '.' . $ext;
        $path = 'platform-logos/' . $filename;

        $contents = file_get_contents($file->getRealPath());
        if ($ext === 'svg' && is_string($contents)) {
            $contents = $this->sanitizeSvg($contents);
        }

        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    protected function deleteLogo(SocialPlatform $platform): void
    {
        if ($platform->logo_path && Storage::disk('public')->exists($platform->logo_path)) {
            Storage::disk('public')->delete($platform->logo_path);
        }
    }

    protected function sanitizeSvg(string $svg): string
    {
        // Strip script tags and event-handler attributes.
        $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg) ?? $svg;
        $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;

        return $svg;
    }

    protected function audit(Request $request, string $action, SocialPlatform $platform, array $before = []): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => SocialPlatform::class,
            'entity_id' => $platform->id,
            'before_state_json' => $before,
            'after_state_json' => $platform->only(['key', 'name', 'brand_color', 'is_active', 'sort_order']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}

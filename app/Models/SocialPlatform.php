<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A social media platform campaigns can target.
 *
 * Managed by Super Admin (name + SVG/PNG logo). The 8 built-in platforms
 * (instagram, tiktok, youtube, x, facebook, whatsapp, telegram, linkedin)
 * render with their original brand glyphs in the frontend; a custom logo
 * upload overrides the glyph when present.
 */
class SocialPlatform extends Model
{
    /** Built-in keys with original brand glyphs in the frontend. */
    public const BUILTIN_KEYS = [
        'instagram',
        'tiktok',
        'youtube',
        'x',
        'facebook',
        'whatsapp',
        'telegram',
        'linkedin',
    ];

    /** Canonical brand colors used when seeding. */
    public const BRAND_COLORS = [
        'instagram' => '#E1306C',
        'tiktok' => '#000000',
        'youtube' => '#FF0000',
        'x' => '#000000',
        'facebook' => '#1877F2',
        'whatsapp' => '#25D366',
        'telegram' => '#229ED9',
        'linkedin' => '#0A66C2',
    ];

    public const NAMES = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'x' => 'X (Twitter)',
        'facebook' => 'Facebook',
        'whatsapp' => 'WhatsApp',
        'telegram' => 'Telegram',
        'linkedin' => 'LinkedIn',
    ];

    protected $fillable = [
        'key',
        'name',
        'logo_path',
        'brand_color',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Public logo URL (null when the platform uses its built-in glyph). */
    public function logoUrl(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return url('storage/' . ltrim($this->logo_path, '/'));
    }
}

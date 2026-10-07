<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurable contributor rank tier (Super Admin managed).
 * Levels: starter → explorer → trusted → pro → elite.
 */
class ContributorRankTier extends Model
{
    protected $fillable = [
        'level',
        'display_name',
        'sort_order',
        'required_tasks',
        'required_earnings_cents',
        'bonus_percent',
        'is_active',
    ];

    protected $casts = [
        'required_tasks' => 'integer',
        'required_earnings_cents' => 'integer',
        'bonus_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** All active tiers ordered lowest → highest. */
    public static function ordered(): \Illuminate\Support\Collection
    {
        return static::where('is_active', true)->orderBy('sort_order')->get();
    }

    public static function forLevel(string $level): ?self
    {
        return static::where('level', $level)->first();
    }
}

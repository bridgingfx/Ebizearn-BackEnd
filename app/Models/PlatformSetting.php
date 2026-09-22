<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected static function cacheKey(string $key): string
    {
        return 'platform_setting:' . $key;
    }

    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever(static::cacheKey($key), function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting ? $setting->value : $default;
        });
    }

    public static function set(string $key, $value, string $group = 'general', bool $isPublic = false): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value, 'group' => $group, 'is_public' => $isPublic]
        );

        Cache::forget(static::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        Cache::forget(static::cacheKey($key));
    }
}

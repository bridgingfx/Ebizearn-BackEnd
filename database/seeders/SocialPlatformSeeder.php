<?php

namespace Database\Seeders;

use App\Models\SocialPlatform;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 built-in social platforms (idempotent).
 */
class SocialPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach (SocialPlatform::BUILTIN_KEYS as $key) {
            SocialPlatform::updateOrCreate(
                ['key' => $key],
                [
                    'name' => SocialPlatform::NAMES[$key],
                    'brand_color' => SocialPlatform::BRAND_COLORS[$key],
                    'is_active' => true,
                    'sort_order' => $order++,
                ]
            );
        }
    }
}

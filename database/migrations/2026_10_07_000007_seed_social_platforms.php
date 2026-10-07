<?php

use Database\Seeders\SocialPlatformSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: seed the 8 built-in social platforms (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SocialPlatformSeeder())->run();
    }

    public function down(): void
    {
        // Reference data — intentionally not removed on rollback.
    }
};

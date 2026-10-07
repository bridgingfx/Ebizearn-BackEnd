<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social platforms targeted by campaigns (additive).
 *
 * Super Admin manages these (name + SVG/PNG logo upload); the active set
 * is served publicly and drives the platform picker everywhere, so a newly
 * added social media appears for admin, moderator and business users
 * without a code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique(); // slug: instagram, tiktok, ...
            $table->string('name', 80); // display name
            $table->string('logo_path', 500)->nullable(); // storage path of uploaded SVG/PNG
            $table->string('brand_color', 16)->nullable(); // hex, e.g. #E1306C
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_platforms');
    }
};

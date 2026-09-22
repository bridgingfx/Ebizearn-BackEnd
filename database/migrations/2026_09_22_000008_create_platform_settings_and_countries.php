<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 (Super-Admin-controlled settings):
     * - platform_settings: key/value store for platform + fraud settings
     *   (group column: general, finance, fraud, referrals, ...).
     * - countries: relational country catalog the Super Admin manages
     *   (replaces the hardcoded config list as the source of truth).
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->text('value')->nullable();
            $table->string('group', 64)->default('general');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();
            $table->string('name', 128);
            $table->string('currency', 4)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
        Schema::dropIfExists('platform_settings');
    }
};

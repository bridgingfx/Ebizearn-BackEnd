<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wizard presets: what the business campaign wizard pre-fills when a Task
 * Library template links to it (?template=key) — task type, category and
 * platform. Managed by Super Admin (Task Library → Dropdown lists).
 * Seeded with the presets that were fixed in code before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_presets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label', 100);
            $table->string('task_type_key', 64)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('task_categories')->nullOnDelete();
            $table->string('platform', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $presets = [
            ['share', 'Share / repost', 'share', 'Instagram'],
            ['tiktok', 'Short video (TikTok)', 'follow', 'TikTok'],
            ['comment', 'Comment / engagement', 'like_comment', 'YouTube'],
            ['app', 'App testing', null, 'Instagram'],
            ['whatsapp', 'WhatsApp status', 'share', 'WhatsApp'],
            ['review', 'Review', null, null],
            ['survey', 'Survey / feedback', null, null],
        ];
        foreach ($presets as $i => [$key, $label, $type, $platform]) {
            DB::table('wizard_presets')->insert([
                'key' => $key,
                'label' => $label,
                'task_type_key' => $type,
                'platform' => $platform,
                'sort_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_presets');
    }
};

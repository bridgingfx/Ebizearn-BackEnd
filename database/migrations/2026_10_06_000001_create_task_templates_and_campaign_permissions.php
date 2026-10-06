<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task Library moves from a hardcoded list in the business portal to
 * database rows Super Admin manages:
 *
 * - task_templates: one row per recipe, with per-audience visibility
 *   (visible_to_business / visible_to_admin) and an active switch.
 * - Permissions: view_task_library (admin + business by default),
 *   manage_task_library (no role by default — Super Admin, or an admin they
 *   grant it to), edit_campaigns / delete_campaigns (admin by default),
 *   edit_own_campaigns / delete_own_campaigns (business by default).
 *
 * The eight templates the business portal shipped with are seeded so the
 * page looks the same after deploy. Data is inlined so this migration stays
 * stable if the model defaults change later.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view_task_library' => 'See the Task Library templates',
        'manage_task_library' => 'Create / edit / delete Task Library templates',
        'edit_campaigns' => "Edit any campaign's details",
        'delete_campaigns' => 'Delete campaigns (no contributor activity only)',
        'edit_own_campaigns' => 'Edit own campaign details',
        'delete_own_campaigns' => 'Delete own campaigns (no contributor activity only)',
    ];

    private const GRANTS = [
        'admin' => ['view_task_library', 'edit_campaigns', 'delete_campaigns'],
        'business' => ['view_task_library', 'edit_own_campaigns', 'delete_own_campaigns'],
    ];

    private const TEMPLATES = [
        ['Instagram Story Share', 'share', 'Ask contributors to share your story, post, or reel to their followers.', '2–5 min', '$0.10 – $0.30', 'share', 'share', 'Instagram'],
        ['TikTok Video / Duet', 'video', 'Contributors create or duet a short video featuring your brand.', '10–20 min', 'Custom (UGC pricing)', 'tiktok', 'follow', 'TikTok'],
        ['YouTube Comment', 'comment', 'Leave a genuine comment on a video to drive engagement.', '2–3 min', '$0.20 platform minimum', 'comment', 'like_comment', 'YouTube'],
        ['App Testing', 'app', 'Install an app, complete a short flow, and report any issues.', '10–15 min', 'Custom', 'app', 'app_test', null],
        ['WhatsApp Status Share', 'whatsapp', 'Share a brand visual to a personal WhatsApp status.', '2–4 min', '$0.10 – $0.30', 'whatsapp', 'share', 'WhatsApp'],
        ['X Repost & Reply', 'at', 'Repost brand content and reply with a genuine comment.', '3–5 min', '$0.10 – $0.30', 'share', 'share', 'X'],
        ['Google Business Review', 'tag', 'Leave an honest review on your Google Business profile.', '3–5 min', '$0.20 – $2.00', 'review', null, null],
        ['Survey / Feedback Form', 'survey', 'Fill out a short survey or feedback questionnaire.', '5–10 min', '$0.20 – $2.00', 'survey', 'survey', null],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('task_templates')) {
            Schema::create('task_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('icon', 32)->default('share');
                $table->text('description');
                $table->string('duration_label', 64)->nullable();
                $table->string('reward_label', 64)->nullable();
                // Wizard hint (?template=...) used by the business campaign wizard.
                $table->string('template_key', 64)->nullable();
                $table->string('task_type_key', 64)->nullable();
                $table->string('platform', 64)->nullable();
                $table->text('instructions')->nullable();
                $table->boolean('visible_to_business')->default(true);
                $table->boolean('visible_to_admin')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            $now = now();
            foreach (self::TEMPLATES as $i => [$name, $icon, $description, $duration, $reward, $key, $type, $platform]) {
                DB::table('task_templates')->insert([
                    'name' => $name,
                    'icon' => $icon,
                    'description' => $description,
                    'duration_label' => $duration,
                    'reward_label' => $reward,
                    'template_key' => $key,
                    'task_type_key' => $type,
                    'platform' => $platform,
                    'visible_to_business' => true,
                    'visible_to_admin' => true,
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'updated_at' => $now]);
            DB::table('permissions')->where('name', $name)->whereNull('created_at')->update(['created_at' => $now]);
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permIds = DB::table('permissions')->pluck('id', 'name');

        foreach (self::GRANTS as $role => $permissions) {
            if (!isset($roleIds[$role])) {
                continue;
            }
            foreach ($permissions as $permission) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleIds[$role],
                    'permission_id' => $permIds[$permission],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

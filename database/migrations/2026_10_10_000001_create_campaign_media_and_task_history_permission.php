<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - campaign_media: photos and videos staff attach to a campaign (shown to
 *   contributors on the task page, downloadable).
 * - view_task_history: the admin Task History page (who took which task,
 *   their proof, its status). Mirrored from review_submissions so everyone
 *   who reviews proofs today can open it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('type', 10); // image | video
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('original_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['campaign_id', 'sort_order']);
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => Permission::VIEW_TASK_HISTORY],
            ['label' => Permission::definitions()[Permission::VIEW_TASK_HISTORY][0], 'created_at' => $now, 'updated_at' => $now]
        );

        $new = DB::table('permissions')->where('name', Permission::VIEW_TASK_HISTORY)->value('id');
        $source = DB::table('permissions')->where('name', Permission::REVIEW_SUBMISSIONS)->value('id');
        if ($new && $source) {
            foreach (DB::table('permission_role')->where('permission_id', $source)->pluck('role_id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $new, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (DB::table('permission_user')->where('permission_id', $source)->get(['user_id', 'is_denied']) as $o) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $o->user_id, 'permission_id' => $new, 'is_denied' => (bool) $o->is_denied,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', Permission::VIEW_TASK_HISTORY)->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permission_user')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Schema::dropIfExists('campaign_media');
    }
};

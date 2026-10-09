<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business profiles on the task page:
 * - user_follows: who follows whom (contributors follow a business owner,
 *   the business can follow back).
 * - business_task_alerts: the bell — contributors who want an in-app
 *   notification when that business publishes a task.
 * - notifications: Laravel's database notifications (new follower, follow
 *   back, new task from a business you follow).
 * - manual_kyc_approve: approve KYC from the user page without documents.
 *   Super Admin holds it; grant it to other roles in Roles & Permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['follower_id', 'following_id']);
            $table->index('following_id');
        });

        Schema::create('business_task_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
            $table->index('business_id');
        });

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => Permission::MANUAL_KYC_APPROVE],
            ['label' => Permission::definitions()[Permission::MANUAL_KYC_APPROVE][0], 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', Permission::MANUAL_KYC_APPROVE)->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permission_user')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('business_task_alerts');
        Schema::dropIfExists('user_follows');
    }
};

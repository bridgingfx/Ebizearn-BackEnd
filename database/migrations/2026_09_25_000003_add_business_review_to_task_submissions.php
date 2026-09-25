<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step proof review. The campaign's business approves or rejects a
 * contributor's proof first (a recommendation — no money moves); staff with
 * review_submissions then confirm the final decision, which is what credits
 * the contributor's wallet. Businesses get review_campaign_proofs by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->string('business_decision', 20)->nullable()->index(); // approved | rejected
            $table->string('business_reason', 1000)->nullable();
            $table->foreignId('business_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('business_reviewed_at')->nullable();
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['name' => 'review_campaign_proofs'],
            ['label' => 'Approve / reject proofs on own campaigns', 'updated_at' => $now]
        );
        DB::table('permissions')->where('name', 'review_campaign_proofs')->whereNull('created_at')->update(['created_at' => $now]);

        $roleId = DB::table('roles')->where('name', 'business')->value('id');
        $permId = DB::table('permissions')->where('name', 'review_campaign_proofs')->value('id');
        if ($roleId && $permId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'review_campaign_proofs')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permission_user')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_reviewer_id');
            $table->dropColumn(['business_decision', 'business_reason', 'business_reviewed_at']);
        });
    }
};

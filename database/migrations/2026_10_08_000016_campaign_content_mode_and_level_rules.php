<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign post content provided to contributors:
 *  - content_mode: manual (one approved text) or auto (each contributor gets
 *    their own AI rewording of the approved text); null = no post content.
 *  - content_status: pending / approved / rejected — staff approve content
 *    before the campaign's tasks are shown to contributors.
 *  - task_assignments.content: the contributor's own version (auto mode).
 *
 * Contributor levels: promotion counts approved tasks only (admin-set
 * thresholds), and staff can set + lock a contributor's level by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'content_mode')) {
                $t->string('content_mode', 10)->nullable()->after('content_brief');
                $t->string('content_status', 10)->nullable()->after('content_mode');
                $t->string('content_review_note', 500)->nullable()->after('content_status');
                $t->foreignId('content_reviewed_by')->nullable()->after('content_review_note')->constrained('users')->nullOnDelete();
                $t->timestamp('content_reviewed_at')->nullable()->after('content_reviewed_by');
            }
        });

        // Campaigns that already show content keep it live.
        DB::table('campaigns')->whereNotNull('generated_content')->where('generated_content', '!=', '')
            ->whereNull('content_mode')
            ->update(['content_mode' => 'manual', 'content_status' => 'approved', 'content_reviewed_at' => now()]);

        Schema::table('task_assignments', function (Blueprint $t) {
            if (!Schema::hasColumn('task_assignments', 'content')) {
                $t->text('content')->nullable();
            }
        });

        Schema::table('profiles', function (Blueprint $t) {
            if (!Schema::hasColumn('profiles', 'level_locked')) {
                $t->boolean('level_locked')->default(false)->after('contributor_level');
            }
        });

        // Levels go up by approved tasks only.
        if (Schema::hasTable('contributor_rank_tiers')) {
            DB::table('contributor_rank_tiers')->update(['required_earnings_cents' => 0]);
        }
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (Schema::hasColumn('campaigns', 'content_mode')) {
                $t->dropConstrainedForeignId('content_reviewed_by');
                $t->dropColumn(['content_mode', 'content_status', 'content_review_note', 'content_reviewed_at']);
            }
        });
        Schema::table('task_assignments', function (Blueprint $t) {
            if (Schema::hasColumn('task_assignments', 'content')) {
                $t->dropColumn('content');
            }
        });
        Schema::table('profiles', function (Blueprint $t) {
            if (Schema::hasColumn('profiles', 'level_locked')) {
                $t->dropColumn('level_locked');
            }
        });
    }
};

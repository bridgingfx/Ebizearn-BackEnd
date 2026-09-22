<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 (3-level affiliate): each referred user now carries one
     * referral row PER level (level 1..N resolved at registration from the
     * referrer chain), instead of a single L1 row.
     *
     * - adds `level` (unsigned tiny int, default 1)
     * - replaces unique(referred_user_id) with
     *   unique(referrer_id, referred_user_id, level) so a referral can never
     *   double-pay, even on retry — the same double-spend guard pattern as
     *   the money-safety unique constraints.
     *
     * Existing rows become level 1; data is preserved on both drivers.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('referrals', function (Blueprint $table) {
                $table->unsignedTinyInteger('level')->default(1)->after('referred_user_id');
            });

            Schema::table('referrals', function (Blueprint $table) {
                $table->dropUnique(['referred_user_id']); // referrals_referred_user_id_unique
                $table->unique(['referrer_id', 'referred_user_id', 'level'], 'referrals_referrer_referee_level_unique');
            });

            return;
        }

        // SQLite: rebuild the table (FKs off — task_submissions references it).
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('referrals_rebuild', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->enum('status', ['pending', 'qualified', 'rewarded'])->default('pending');
            $table->timestamp('qualified_at')->nullable();
            $table->unsignedBigInteger('reward_cents')->default(100);
            $table->timestamps();

            $table->unique(['referrer_id', 'referred_user_id', 'level'], 'referrals_referrer_referee_level_unique');
            $table->index(['referrer_id', 'status']);
        });

        DB::statement(
            'INSERT INTO "referrals_rebuild" ("id", "referrer_id", "referred_user_id", "level", "status", "qualified_at", "reward_cents", "created_at", "updated_at") ' .
            'SELECT "id", "referrer_id", "referred_user_id", 1, "status", "qualified_at", "reward_cents", "created_at", "updated_at" FROM "referrals"'
        );

        Schema::drop('referrals');
        Schema::rename('referrals_rebuild', 'referrals');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Keep only level-1 rows so the old unique(referred_user_id) holds.
        DB::table('referrals')->where('level', '>', 1)->delete();

        if (DB::getDriverName() === 'mysql') {
            Schema::table('referrals', function (Blueprint $table) {
                $table->dropUnique('referrals_referrer_referee_level_unique');
                $table->unique('referred_user_id');
                $table->dropColumn('level');
            });

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('referrals_rebuild', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'qualified', 'rewarded'])->default('pending');
            $table->timestamp('qualified_at')->nullable();
            $table->unsignedBigInteger('reward_cents')->default(100);
            $table->timestamps();

            $table->unique('referred_user_id');
            $table->index(['referrer_id', 'status']);
        });

        DB::statement(
            'INSERT INTO "referrals_rebuild" ("id", "referrer_id", "referred_user_id", "status", "qualified_at", "reward_cents", "created_at", "updated_at") ' .
            'SELECT "id", "referrer_id", "referred_user_id", "status", "qualified_at", "reward_cents", "created_at", "updated_at" FROM "referrals"'
        );

        Schema::drop('referrals');
        Schema::rename('referrals_rebuild', 'referrals');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};

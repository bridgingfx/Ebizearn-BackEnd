<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic double-submit guard for task proof submissions.
 *
 * POST /api/v1/tasks/{id}/submit already answers 409 when a submission
 * exists, but the exists() check and the insert were not atomic — two
 * concurrent requests could both pass the check and create duplicate rows.
 * The unique(task_id, user_id) key makes the database the final authority;
 * TaskController::submit already catches the resulting QueryException and
 * maps it to the honest 409 "already submitted" response.
 *
 * Rollback-safe on SQLite and MySQL: plain index add/drop, no alters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->unique(['task_id', 'user_id'], 'task_submissions_task_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropUnique('task_submissions_task_user_unique');
        });
    }
};

<?php

use App\Services\Email\EmailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "New task available" emails (App\Services\Tasks\NewTaskAnnouncer).
 *
 * - tasks.announce_status: null (not live yet) → pending → sending → done,
 *   or skipped. announce_cursor is the last contributor id emailed.
 * - Tasks that already exist are marked skipped so contributors aren't
 *   flooded with old tasks, and resuming a paused one doesn't announce it.
 * - Adds the new_task_available email template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('announce_status', 16)->nullable()->index();
            $table->unsignedBigInteger('announce_cursor')->default(0);
            $table->timestamp('announced_at')->nullable();
        });

        DB::table('tasks')->update(['announce_status' => 'skipped']);

        $template = EmailTemplateDefaults::find('new_task_available');
        if ($template && !DB::table('email_templates')->where('event_key', $template['event_key'])->exists()) {
            DB::table('email_templates')->insert([
                'event_key' => $template['event_key'],
                'name' => $template['name'],
                'subject' => $template['subject'],
                'html_body' => $template['html_body'],
                'text_body' => $template['text_body'],
                'variables' => json_encode($template['variables']),
                'is_enabled' => true,
                'is_custom' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('event_key', 'new_task_available')->delete();

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['announce_status']);
            $table->dropColumn(['announce_status', 'announce_cursor', 'announced_at']);
        });
    }
};

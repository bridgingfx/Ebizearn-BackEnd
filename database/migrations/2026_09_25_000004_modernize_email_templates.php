<?php

use App\Services\Email\EmailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - email_templates.is_custom: templates Super Admin creates in the editor
 *   (system templates are tied to platform events and can't be deleted).
 * - Upgrades the built-in templates to the new branded layout (logo header,
 *   hero, details, footer). Only rows still in the original plain design are
 *   replaced, so a template an admin rewrote from scratch is left alone.
 *   Missing built-in templates are added.
 */
return new class extends Migration
{
    /** The first bytes of every template in the original plain design. */
    private const OLD_DESIGN = '<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px">';

    public function up(): void
    {
        if (!Schema::hasColumn('email_templates', 'is_custom')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->boolean('is_custom')->default(false)->after('is_enabled');
            });
        }

        $now = now();
        foreach (EmailTemplateDefaults::all() as $template) {
            $row = DB::table('email_templates')->where('event_key', $template['event_key'])->first();

            if (!$row) {
                DB::table('email_templates')->insert([
                    'event_key' => $template['event_key'],
                    'name' => $template['name'],
                    'subject' => $template['subject'],
                    'html_body' => $template['html_body'],
                    'text_body' => $template['text_body'],
                    'variables' => json_encode($template['variables']),
                    'is_enabled' => true,
                    'is_custom' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $update = ['variables' => json_encode($template['variables']), 'updated_at' => $now];
            if (str_starts_with((string) $row->html_body, self::OLD_DESIGN)) {
                $update['html_body'] = $template['html_body'];
                $update['text_body'] = $template['text_body'];
            }
            DB::table('email_templates')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('is_custom', true)->delete();

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('is_custom');
        });
    }
};

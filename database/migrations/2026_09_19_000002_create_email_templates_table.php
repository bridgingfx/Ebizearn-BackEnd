<?php

use App\Services\Email\EmailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 60)->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('html_body');
            $table->longText('text_body');
            $table->json('variables');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        foreach (EmailTemplateDefaults::all() as $template) {
            DB::table('email_templates')->insert([
                'event_key' => $template['event_key'],
                'name' => $template['name'],
                'subject' => $template['subject'],
                'html_body' => $template['html_body'],
                'text_body' => $template['text_body'],
                'variables' => json_encode($template['variables']),
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

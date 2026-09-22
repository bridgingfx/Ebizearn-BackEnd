<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 20); // smtp, brevo, sendgrid, mailgun, ses, log
            $table->string('host')->nullable(); // SMTP host or Mailgun domain
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('secret')->nullable(); // encrypted password / API key
            $table->string('encryption', 10)->nullable(); // tls, ssl, none
            $table->string('region', 30)->nullable(); // SES region / Mailgun us|eu
            $table->string('from_email');
            $table->string('from_name');
            $table->boolean('is_active')->default(false);
            $table->string('status', 20)->default('untested'); // untested, ok, failed
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_providers');
    }
};

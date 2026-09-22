<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 60)->index();
            $table->foreignId('email_provider_id')->nullable()->constrained('email_providers')->nullOnDelete();
            $table->string('provider_name')->nullable();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->string('status', 20)->index(); // sent, failed, logged, skipped
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 40);
            $table->string('display_name')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('status', 30)->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();

            $table->index(['driver', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};

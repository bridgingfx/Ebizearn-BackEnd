<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo requests (public "request a demo" form submissions). Stored as real
 * DB rows for the sales/admin team to work through; triaged via `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('company', 160);
            $table->text('message');
            $table->string('status', 32)->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};

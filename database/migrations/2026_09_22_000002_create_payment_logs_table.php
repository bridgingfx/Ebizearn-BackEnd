<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 80);
            $table->foreignId('payment_gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_name')->nullable();
            $table->string('direction', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 4)->default('USD');
            $table->string('status', 30);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};

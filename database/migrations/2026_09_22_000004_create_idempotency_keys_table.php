<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency ledger for money-moving operations.
     *
     * A client-supplied key (Idempotency-Key header or idempotency_key field)
     * is recorded before a credit/debit/withdrawal/hold is applied. Retries
     * with the same key + same parameters return the original result instead
     * of double-applying; the same key with different parameters is rejected.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('action', 64); // e.g. wallet.credit, wallet.debit, wallet.withdraw, wallet.hold
            $table->string('fingerprint', 64); // sha256 of the operation parameters
            $table->string('result_type')->nullable(); // model class of the created record
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

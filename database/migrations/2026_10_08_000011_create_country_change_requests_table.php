<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residence-country changes need staff approval. A client's country edit
 * creates a pending request instead of changing the profile; on approval
 * the country switches and KYC must be redone for the new country (tasks
 * stay locked until it is approved — see TaskController::kycLockResponse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('from_country', 2)->nullable();
            $table->string('to_country', 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_change_requests');
    }
};

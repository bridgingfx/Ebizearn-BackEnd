<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 (withdrawal rules): DB-backed selectable withdrawal minimums.
     * Exactly one row is active at a time; the active value is what every
     * withdrawal enforcement path reads (via WithdrawalRule::currentMinCents).
     * Seeded with the owner-approved options: $10 / $25 / $50 / $100.
     */
    public function up(): void
    {
        Schema::create('withdrawal_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('amount_cents')->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_rules');
    }
};

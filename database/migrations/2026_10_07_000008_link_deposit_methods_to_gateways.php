<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a deposit method to a payment gateway (additive).
 *
 * When a deposit method has an active gateway with credentials (e.g. card
 * -> Stripe), the business "Add funds" flow becomes automatic: the customer
 * pays through the gateway and the wallet is credited on webhook
 * confirmation. Methods without a gateway stay manual (admin approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_methods', function (Blueprint $table) {
            $table->foreignId('payment_gateway_id')->nullable()->after('key')
                ->constrained('payment_gateways')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_gateway_id');
        });
    }
};

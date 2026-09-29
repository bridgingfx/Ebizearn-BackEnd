<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * USDT (manual-approved) payouts: the contributor's receiving address +
     * network live on the withdrawal request; tx_hash is recorded by the
     * admin after sending USDT from the company wallet. Ledger stays USD
     * (1 USDT = $1) — no arithmetic changes.
     */
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('wallet_address', 128)->nullable()->after('payout_details_json');
            $table->string('network', 16)->nullable()->default('TRC-20')->after('wallet_address');
            $table->string('tx_hash', 128)->nullable()->after('provider_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn(['wallet_address', 'network', 'tx_hash']);
        });
    }
};

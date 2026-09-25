<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business wallet deposits.
 *
 * deposit_methods: the four ways a business can add funds (card payment link,
 * crypto, bank transfer, email request). Super Admin switches each on/off and
 * fills in its details; only active methods are shown on Business → Billing.
 *
 * deposit_requests: a business says "I paid X via method Y (reference Z)".
 * Staff check the money arrived and approve — only then is the business
 * wallet credited (wallet_transactions type `deposit`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_methods', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique(); // card | crypto | bank | email
            $table->string('title', 100);
            $table->boolean('is_active')->default(false);
            $table->text('instructions')->nullable();
            $table->json('details')->nullable(); // per-method fields (IBAN, wallet address, payment link…)
            $table->unsignedBigInteger('min_amount_cents')->default(1000);
            $table->unsignedBigInteger('max_amount_cents')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $methods = [
            ['card', 'Card payment', 'Pay securely by debit or credit card using our payment link, then enter the payment reference below.', 1],
            ['bank', 'Bank transfer', 'Transfer the amount to the bank account below. Use your company name as the payment reference.', 2],
            ['crypto', 'Crypto (USDT)', 'Send USDT to the wallet address below on the network shown. Paste the transaction hash when done.', 3],
            ['email', 'Request by email', 'Tell us how much you want to add and our finance team will email you an invoice with payment details.', 4],
        ];
        foreach ($methods as [$key, $title, $instructions, $order]) {
            DB::table('deposit_methods')->insert([
                'key' => $key,
                'title' => $title,
                'is_active' => false,
                'instructions' => $instructions,
                'details' => json_encode([]),
                'min_amount_cents' => 1000,
                'max_amount_cents' => null,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('deposit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 4)->default('USD');
            $table->string('reference', 255)->nullable(); // tx hash / bank ref / payment id
            $table->text('note')->nullable();
            $table->string('proof_path', 500)->nullable(); // private disk
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_requests');
        Schema::dropIfExists('deposit_methods');
    }
};

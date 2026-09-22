<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 — social login: link external provider identities to users.
 *
 * One row per (provider, provider_sub) — the stable subject claim from the
 * verified ID token. user_id links the identity to a local account (one
 * account may hold several linked identities; e.g. password + Google).
 * email is a convenience copy of the provider-supplied address at link time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20); // google | apple
            $table->string('provider_sub', 255); // stable subject claim from the ID token
            $table->string('email', 255)->nullable(); // provider-supplied email at link time
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_sub'], 'social_accounts_provider_sub_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

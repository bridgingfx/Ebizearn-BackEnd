<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth ("Connect with …") for contributor social channels + the robo
 * auto-verifier. Tokens are encrypted by the app before they reach these
 * columns — the database never holds a usable token in plain text.
 *
 * connected_via: 'manual' (paste link + bio code + staff review, as before)
 *                'oauth'  (platform login; the handshake itself proves ownership)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->string('connected_via', 16)->default('manual')->after('status');
            $table->string('oauth_provider_user_id', 191)->nullable()->after('connected_via');
            $table->string('oauth_username', 191)->nullable()->after('oauth_provider_user_id');
            $table->text('oauth_access_token')->nullable()->after('oauth_username');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_access_token');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_refresh_token');
            $table->timestamp('last_robo_check_at')->nullable()->after('oauth_expires_at');
            $table->string('robo_check_note', 500)->nullable()->after('last_robo_check_at');
            $table->unsignedTinyInteger('robo_failures')->default(0)->after('robo_check_note');
        });
    }

    public function down(): void
    {
        Schema::table('social_channels', function (Blueprint $table) {
            $table->dropColumn([
                'connected_via', 'oauth_provider_user_id', 'oauth_username',
                'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at',
                'last_robo_check_at', 'robo_check_note', 'robo_failures',
            ]);
        });
    }
};

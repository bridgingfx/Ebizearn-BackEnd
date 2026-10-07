<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link contributors must engage with (additive 2026-10-07).
 *
 * A Follow/Like/Share task is meaningless without the URL of the profile
 * or post to engage with. Required in the UI for engagement task types,
 * optional for the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('target_url', 2000)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('target_url');
        });
    }
};

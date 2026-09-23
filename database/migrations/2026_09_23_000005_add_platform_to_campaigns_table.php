<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 — campaign creation wizard: persist the platform choice.
 *
 * The business wizard collects a platform (Instagram, TikTok, YouTube, …)
 * in step 2, but the value never reached the database: the draft endpoint
 * accepted it yet only stashed it inside proof_requirements_json, and the
 * create endpoint (POST /business/campaigns, used by the wizard UI)
 * rejected it outright. This adds a first-class nullable `platform` column
 * so the choice is queryable, survives on the campaign row itself, and can
 * be copied onto the materialized task pool at launch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('platform', 64)->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};

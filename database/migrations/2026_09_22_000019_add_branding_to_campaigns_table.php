<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Priority 4 — business campaign branding.
 *
 * Campaigns carry the company name + logo shown to contributors in the
 * task feed. logo_path is set via the business campaign logo-upload
 * endpoint (validated image, stored on the public disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('title');
            $table->string('logo_path')->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'logo_path']);
        });
    }
};

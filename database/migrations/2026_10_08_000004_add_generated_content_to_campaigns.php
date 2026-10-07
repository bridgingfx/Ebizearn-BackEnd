<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI-generated post content for contributors to copy-paste
     * (e.g. Instagram caption with hashtags, Google review text).
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('generated_content')->nullable()->after('target_url');
            $table->string('content_brief', 500)->nullable()->after('generated_content');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['generated_content', 'content_brief']);
        });
    }
};

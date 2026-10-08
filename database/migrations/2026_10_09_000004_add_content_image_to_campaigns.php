<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Image contributors post together with the campaign's post text. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('campaigns', 'content_image_path')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->string('content_image_path')->nullable()->after('generated_content');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaigns', 'content_image_path')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropColumn('content_image_path');
            });
        }
    }
};

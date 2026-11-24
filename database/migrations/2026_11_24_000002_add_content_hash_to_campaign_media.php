<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug 7 (bug report 2026-11-20): poster re-uploads created duplicate rows
 * because nothing identified identical bytes. A sha256 content hash plus a
 * unique (campaign_id, content_hash) pair makes attach idempotent —
 * re-uploading the same file for the same campaign returns the existing
 * record. Existing rows keep NULL hashes (not backfilled — their bytes
 * live in storage, not in the DB); dedup applies to uploads from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_media', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('path');
            $table->unique(['campaign_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_media', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};

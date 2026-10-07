<?php

use App\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair campaign targeting saved before Campaign::normalizeTargetCountries
 * existed. The business wizard stored "worldwide" as ['GLOBAL'] (and some
 * rows hold lowercase codes or an empty list); the task feed only matches
 * 'ALL' or the contributor's uppercase ISO code, so those campaigns' tasks
 * were invisible to every contributor.
 *
 * Writes through the query builder (no model events / timestamps touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('campaigns')->select(['id', 'target_countries_json'])->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $raw = $row->target_countries_json === null ? null : json_decode($row->target_countries_json, true);
                    if ($raw === null) {
                        continue; // null already reads as global in the feed
                    }

                    $normalized = Campaign::normalizeTargetCountries($raw);
                    if ($normalized !== $raw) {
                        DB::table('campaigns')->where('id', $row->id)
                            ->update(['target_countries_json' => json_encode($normalized)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data repair only — the old values were not meaningful.
    }
};

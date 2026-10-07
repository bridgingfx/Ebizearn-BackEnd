<?php

use App\Models\TaskType;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill the task-type catalog on environments that were never seeded
 * (production never runs DatabaseSeeder). Without these rows GET /task-types
 * is empty and no campaign or task can be created — the "Task type" picker
 * has nothing to offer.
 *
 * Insert-only: keys that already exist are left untouched, so reward bands
 * a Super Admin tuned through the ops API are never reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TaskType::defaults() as $key => $attributes) {
            TaskType::firstOrCreate(['key' => $key], $attributes);
        }
    }

    public function down(): void
    {
        // Catalog rows may be referenced by tasks; never delete on rollback.
    }
};

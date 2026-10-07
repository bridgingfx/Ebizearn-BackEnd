<?php

namespace Database\Seeders;

use App\Models\TaskType;
use Illuminate\Database\Seeder;

/**
 * Standalone task-type seeder (idempotent).
 *
 * Seeds the canonical task-type catalog (names, proof contracts, reward
 * bands, allowed platforms) from config/task_types.php. Uses updateOrCreate
 * on the unique key, so re-running never duplicates rows.
 *
 * Added 2026-10-07: production's task_types table was found empty, which
 * left the admin "Post a campaign" Task Type dropdown blank. Super Admin
 * can run this from the admin panel (POST /admin/ops/task-types/seed)
 * without server access. The main DatabaseSeeder keeps its own copy of
 * this logic untouched.
 */
class TaskTypeSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function run(): array
    {
        $created = 0;
        $updated = 0;

        foreach (TaskType::defaults() as $key => $attributes) {
            $type = TaskType::updateOrCreate(['key' => $key], $attributes);
            if ($type->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'total' => $created + $updated];
    }
}

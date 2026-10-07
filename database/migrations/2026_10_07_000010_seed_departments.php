<?php

use Database\Seeders\DepartmentSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: seed the standard departments (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DepartmentSeeder())->run();
    }

    public function down(): void
    {
        // Reference data — intentionally not removed on rollback.
    }
};

<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: grant the manage_roles permission to the admin role
 * (idempotent). Superadmin holds every permission implicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => Permission::MANAGE_ROLES],
            ['label' => 'Manage roles, departments & permissions']
        );

        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            $admin->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        // Permission grant intentionally kept on rollback.
    }
};

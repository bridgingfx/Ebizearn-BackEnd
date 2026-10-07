<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments for organizing staff (additive 2026-10-07).
 *
 * Admins belong to a department (Finance, Support, ...); roles can be
 * scoped to a department. Roles management is shared between superadmin
 * (full control) and admin (manage_roles permission, with guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('role')
                ->constrained('departments')->nullOnDelete();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('is_system')
                ->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('departments');
    }
};

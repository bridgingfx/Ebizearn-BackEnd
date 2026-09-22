<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 (role separation): the users.role enum gains 'moderator'.
     * Canonical roles: contributor, business, moderator, admin, superadmin.
     *
     * MySQL extends the native ENUM in place. SQLite compiles enum() to a
     * CHECK constraint, so the users table is rebuilt with the wider list
     * (same pattern as the wallet_transactions enum rebuild).
     */
    public function up(): void
    {
        $roles = ['contributor', 'business', 'moderator', 'admin', 'superadmin'];

        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($r) => "'{$r}'", $roles));
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM({$list}) NOT NULL DEFAULT 'contributor'");
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('users_rebuild', function (Blueprint $table) use ($roles) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', $roles)->default('contributor');
            $table->enum('status', ['active', 'suspended', 'pending_verification'])->default('active');
            $table->string('referral_code', 32)->nullable()->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        $columns = [
            'id', 'uuid', 'name', 'email', 'email_verified_at', 'password',
            'role', 'status', 'referral_code', 'referrer_id', 'remember_token',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $list = implode(',', array_map(fn ($c) => "\"{$c}\"", $columns));
        DB::statement("INSERT INTO \"users_rebuild\" ({$list}) SELECT {$list} FROM \"users\"");

        Schema::drop('users');
        Schema::rename('users_rebuild', 'users');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // A moderator row cannot survive the shrink; demote leftovers to
        // contributor before restoring the original enum.
        DB::table('users')->where('role', 'moderator')->update(['role' => 'contributor']);

        $roles = ['contributor', 'business', 'admin', 'superadmin'];

        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($r) => "'{$r}'", $roles));
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM({$list}) NOT NULL DEFAULT 'contributor'");
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('users_rebuild', function (Blueprint $table) use ($roles) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', $roles)->default('contributor');
            $table->enum('status', ['active', 'suspended', 'pending_verification'])->default('active');
            $table->string('referral_code', 32)->nullable()->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        $columns = [
            'id', 'uuid', 'name', 'email', 'email_verified_at', 'password',
            'role', 'status', 'referral_code', 'referrer_id', 'remember_token',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $list = implode(',', array_map(fn ($c) => "\"{$c}\"", $columns));
        DB::statement("INSERT INTO \"users_rebuild\" ({$list}) SELECT {$list} FROM \"users\"");

        Schema::drop('users');
        Schema::rename('users_rebuild', 'users');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin can assign contributors, businesses and moderators to a
 * staff account (admin / moderator). A staff account with at least one
 * assigned user only sees and manages those users and their data
 * (App\Services\Staff\StaffScope); with none it keeps full access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('managed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('managed_by');
        });
    }
};

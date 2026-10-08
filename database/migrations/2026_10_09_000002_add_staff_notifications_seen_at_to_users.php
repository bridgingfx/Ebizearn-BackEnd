<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff notification bell: activity after this time counts as unread for
 * that staff member (opening the bell / page marks everything seen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'staff_notifications_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('staff_notifications_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'staff_notifications_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('staff_notifications_seen_at');
            });
        }
    }
};

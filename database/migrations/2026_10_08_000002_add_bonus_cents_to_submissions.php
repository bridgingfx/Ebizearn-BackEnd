<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Track the rank bonus paid on each approved submission. */
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->unsignedInteger('bonus_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropColumn('bonus_cents');
        });
    }
};

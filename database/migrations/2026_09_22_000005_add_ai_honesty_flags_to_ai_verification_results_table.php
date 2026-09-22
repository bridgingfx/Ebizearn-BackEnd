<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_verification_results', function (Blueprint $table) {
            $table->boolean('ai_simulated')->default(false)->after('raw_payload_json');
            $table->string('ai_label', 120)->nullable()->after('ai_simulated');
        });

        // Every result produced before this column existed came from the mock
        // provider — label them honestly as simulated.
        DB::table('ai_verification_results')->update([
            'ai_simulated' => true,
            'ai_label' => 'Simulated heuristic (pre-launch)',
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_verification_results', function (Blueprint $table) {
            $table->dropColumn(['ai_simulated', 'ai_label']);
        });
    }
};

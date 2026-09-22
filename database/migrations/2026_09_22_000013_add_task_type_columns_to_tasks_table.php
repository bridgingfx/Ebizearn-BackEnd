<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: tasks carry their type contract.
 *
 * All columns are nullable/additive so existing tasks keep working; the
 * task-creation APIs (admin/moderator + business wizard) populate them for
 * new tasks. task_type_id links to task_types; band validation for the
 * reward lives in RewardBandService (Phase 12), not in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('task_type_id')->nullable()->after('category_id')->constrained('task_types')->nullOnDelete();
            $table->string('platform', 64)->nullable()->after('task_type_id');
            $table->string('country_code', 8)->nullable()->after('platform');
            $table->text('instructions')->nullable()->after('country_code');
            $table->json('proof_required_json')->nullable()->after('instructions');
            $table->unsignedInteger('retention_days')->nullable()->after('proof_required_json');
            $table->json('fraud_rules_json')->nullable()->after('retention_days');
            $table->string('company_name')->nullable()->after('fraud_rules_json');
            $table->string('company_logo_url', 2000)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['task_type_id']);
            $table->dropColumn([
                'task_type_id',
                'platform',
                'country_code',
                'instructions',
                'proof_required_json',
                'retention_days',
                'fraud_rules_json',
                'company_name',
                'company_logo_url',
            ]);
        });
    }
};

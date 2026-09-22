<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Priority 4 — campaign admin-approval gate.
 *
 * Adds `pending_review` to campaigns.status. The launch() flow now parks
 * funded campaigns in `pending_review`; a staff reviewer approves them to
 * `active` (tasks become visible in the contributor feed) or cancels them
 * (escrow refunded). The contributor task feed only lists tasks whose
 * campaign is `active`.
 *
 * MySQL: native ENUM altered in place. SQLite: table rebuilt (CHECK
 * constraint cannot be altered); FKs from tasks are preserved by copying
 * the full row set across with constraints disabled during the swap.
 */
return new class extends Migration
{
    protected function statuses(): array
    {
        return ['draft', 'pending_review', 'active', 'paused', 'completed', 'cancelled'];
    }

    protected function originalStatuses(): array
    {
        return ['draft', 'active', 'paused', 'completed', 'cancelled'];
    }

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($s) => "'{$s}'", $this->statuses()));
            DB::statement("ALTER TABLE `campaigns` MODIFY COLUMN `status` ENUM({$list}) NOT NULL DEFAULT 'active'");
        } else {
            $this->rebuildCampaignStatusEnum($this->statuses());
        }
    }

    public function down(): void
    {
        // Park any pending_review rows back to draft so the shrink is safe.
        DB::table('campaigns')->where('status', 'pending_review')->update(['status' => 'draft']);

        if (DB::getDriverName() === 'mysql') {
            $list = implode(',', array_map(fn ($s) => "'{$s}'", $this->originalStatuses()));
            DB::statement("ALTER TABLE `campaigns` MODIFY COLUMN `status` ENUM({$list}) NOT NULL DEFAULT 'active'");
        } else {
            $this->rebuildCampaignStatusEnum($this->originalStatuses());
        }
    }

    protected function rebuildCampaignStatusEnum(array $statuses): void
    {
        $table = 'campaigns';
        // Unique temp name per run: a previous up() leaves indexes named
        // after the temp table on the real table (SQLite rename keeps index
        // names), which would collide on the next rebuild.
        $temp = $table . '_status_rebuild_' . time();

        Schema::disableForeignKeyConstraints();

        // Idempotent: a previous interrupted run may have left the temp table.
        // Use raw SQL — the Schema builder can leave orphaned indexes on SQLite.
        DB::statement("DROP TABLE IF EXISTS \"{$temp}\"");

        // Recreate the table definition from the original migration with the
        // extended status enum. Columns mirrored EXACTLY from
        // 2026_09_16_000004_create_campaigns_table.
        Schema::create($temp, function (Blueprint $blueprint) use ($statuses) {
            $blueprint->id();
            $blueprint->uuid('uuid')->unique();
            $blueprint->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $blueprint->foreignId('category_id')->constrained('task_categories');
            $blueprint->string('title');
            $blueprint->string('objective')->nullable();
            $blueprint->text('description');
            $blueprint->longText('instructions_markdown')->nullable();
            $blueprint->json('proof_requirements_json')->nullable();
            $blueprint->enum('status', $statuses)->default('active');
            $blueprint->unsignedBigInteger('total_budget_cents');
            $blueprint->unsignedBigInteger('remaining_budget_cents');
            $blueprint->unsignedBigInteger('reserved_budget_cents')->default(0);
            $blueprint->unsignedBigInteger('reward_per_task_cents');
            $blueprint->unsignedBigInteger('platform_fee_cents')->default(0);
            $blueprint->unsignedInteger('target_contributors_count');
            $blueprint->unsignedInteger('completed_contributors_count')->default(0);
            $blueprint->json('target_countries_json')->nullable();
            $blueprint->json('target_languages_json')->nullable();
            $blueprint->enum('min_contributor_level', ['starter', 'explorer', 'trusted', 'pro', 'elite'])->default('starter');
            $blueprint->unsignedInteger('retention_hours')->default(24);
            $blueprint->timestamp('starts_at')->nullable();
            $blueprint->timestamp('ends_at')->nullable();
            $blueprint->timestamps();
            $blueprint->softDeletes();
        });

        $columns = Schema::getColumnListing($table);
        $cols = implode(',', array_map(fn ($c) => "\"{$c}\"", $columns));
        DB::statement("INSERT INTO \"{$temp}\" ({$cols}) SELECT {$cols} FROM \"{$table}\"");
        Schema::drop($table);
        Schema::rename($temp, $table);

        Schema::enableForeignKeyConstraints();
    }
};

<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\TaskCategory;
use App\Models\TaskType;
use App\Models\User;
use App\Models\WizardPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Task Library → Dropdown lists: task types, categories, wizard presets. */
class DropdownListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeUser(string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($role), 'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE']);

        return $user;
    }

    public function test_super_admin_adds_and_removes_a_task_type(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));

        $this->postJson('/api/v1/ops/task-types', ['name' => 'Story Repost', 'reward_band_min_cents' => 10, 'reward_band_max_cents' => 5])
            ->assertStatus(422);
        $this->postJson('/api/v1/ops/task-types', ['name' => 'Story Repost', 'reward_band_min_cents' => 10, 'reward_band_max_cents' => 30])
            ->assertCreated()->assertJsonPath('data.key', 'story_repost');
        $this->getJson('/api/v1/task-types')->assertOk();
        $this->assertTrue(collect($this->getJson('/api/v1/task-types')->json('data'))->pluck('key')->contains('story_repost'));

        // Unused → really deleted.
        $this->deleteJson('/api/v1/ops/task-types/story_repost')->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(TaskType::where('key', 'story_repost')->first());
    }

    public function test_in_use_options_are_switched_off_not_deleted(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $type = TaskType::where('is_active', true)->firstOrFail();
        WizardPreset::create(['key' => 'uses_type', 'label' => 'Uses type', 'task_type_key' => $type->key]);

        $this->deleteJson("/api/v1/ops/task-types/{$type->key}")->assertOk()->assertJsonPath('data.deactivated', true);
        $this->assertFalse($type->fresh()->is_active);
        $this->assertFalse(collect($this->getJson('/api/v1/task-types')->json('data'))->pluck('key')->contains($type->key));
    }

    public function test_categories_can_be_deleted(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->postJson('/api/v1/ops/task-categories', ['slug' => 'giveaways', 'name' => 'Giveaways'])->assertCreated();
        $id = TaskCategory::where('slug', 'giveaways')->value('id');
        $this->deleteJson("/api/v1/ops/task-categories/{$id}")->assertOk()->assertJsonPath('data.deleted', true);
    }

    public function test_wizard_presets_crud_and_public_list(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->getJson('/api/v1/ops/wizard-presets')->assertOk()->assertJsonCount(7, 'data');

        $id = $this->postJson('/api/v1/ops/wizard-presets', ['label' => 'Google Review', 'platform' => 'Google'])
            ->assertCreated()->assertJsonPath('data.key', 'google_review')->json('data.id');
        $this->patchJson("/api/v1/ops/wizard-presets/{$id}", ['label' => 'Google Maps review', 'platform' => 'Google'])
            ->assertOk()->assertJsonPath('data.label', 'Google Maps review');

        $this->assertTrue(collect($this->getJson('/api/v1/wizard-presets')->json('data'))->pluck('key')->contains('google_review'));
        $this->deleteJson("/api/v1/ops/wizard-presets/{$id}")->assertOk()->assertJsonPath('data.deleted', true);
    }

    public function test_admins_cannot_manage_lists(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson('/api/v1/ops/wizard-presets', ['label' => 'Nope'])->assertForbidden();
        $this->deleteJson('/api/v1/ops/task-types/follow')->assertForbidden();
    }
}

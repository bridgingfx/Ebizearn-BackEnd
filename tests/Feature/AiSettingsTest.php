<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AI\ContentGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super Admin → Settings → AI content generator: provider, encrypted key,
 * model. The saved settings drive post generation and the safety check.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AQ.test-gemini-key-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['services.openai.key' => null]);
    }

    protected function makeUser(string $role): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Profile::forceCreate(['user_id' => $user->id, 'country_code' => 'AE']);

        return $user;
    }

    private function gemini(string $text, ?string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => $finish]]];
    }

    public function test_super_admin_saves_a_key_that_is_encrypted_and_never_returned(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));

        $this->putJson('/api/v1/admin/ai-settings', [
            'provider' => 'gemini', 'model' => '', 'enabled' => true, 'api_key' => self::KEY,
        ])->assertOk()
            ->assertJsonPath('data.provider', 'gemini')
            ->assertJsonPath('data.model', 'gemini-flash-latest')
            ->assertJsonPath('data.has_key', true)
            ->assertJsonPath('data.key_hint', '••••1234')
            ->assertJsonMissing(['api_key' => self::KEY]);

        $stored = SystemSetting::where('key', 'ai_api_key')->value('value');
        $this->assertNotSame(self::KEY, $stored);
        $this->assertStringNotContainsString(self::KEY, $this->getJson('/api/v1/admin/ai-settings')->getContent());

        // Saving again with a blank key keeps the saved one.
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'gemini', 'enabled' => true])
            ->assertJsonPath('data.has_key', true);
    }

    public function test_only_super_admin_can_see_or_change_ai_settings(): void
    {
        SystemSetting::set('ai_api_key', encrypt('x'), 'ai');
        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson('/api/v1/admin/ai-settings')->assertForbidden();
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'openai', 'enabled' => true])->assertForbidden();
        // Not visible or changeable through the general settings either.
        $keys = collect($this->getJson('/api/v1/admin/system-settings')->json('data'))->pluck('key');
        $this->assertNotContains('ai_api_key', $keys);
        $this->patchJson('/api/v1/admin/system-settings', ['key' => 'ai_api_key', 'value' => 'hijack'])->assertForbidden();
    }

    public function test_gemini_writes_the_post_and_its_safety_filter_is_respected(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'gemini', 'enabled' => true, 'api_key' => self::KEY])->assertOk();

        // One call: Gemini screens the request and answer with its own
        // strict safety filters, so no separate moderation calls.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini('Fresh coffee daily at Bean House! #coffee'))]);
        $result = app(ContentGeneratorService::class)->generate('instagram', 'Promote our coffee shop');
        $this->assertTrue($result['success']);
        $this->assertSame('Fresh coffee daily at Bean House! #coffee', $result['content']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', self::KEY)
            && str_contains($r->url(), 'models/gemini-flash-latest:generateContent'));
    }

    public function test_gemini_safety_block_is_reported_as_inappropriate(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'gemini', 'enabled' => true, 'api_key' => self::KEY])->assertOk();

        // Gemini's own safety filter blocks the brief → treated as unsafe.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
        $blocked = app(ContentGeneratorService::class)->generate('instagram', 'Promote our coffee shop');
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('appropriate', $blocked['message']);
    }

    public function test_connection_test_reports_success_and_failure(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->postJson('/api/v1/admin/ai-settings/test')->assertStatus(422); // no key yet

        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'gemini', 'enabled' => true, 'api_key' => self::KEY])->assertOk();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini('OK'))]);
        $this->postJson('/api/v1/admin/ai-settings/test')->assertOk()->assertJsonPath('data.reply', 'OK');
    }

    public function test_connection_test_shows_the_provider_error(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'gemini', 'enabled' => true, 'api_key' => self::KEY])->assertOk();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);
        $this->postJson('/api/v1/admin/ai-settings/test')->assertStatus(422)
            ->assertJsonPath('message', 'Connection failed: API key not valid.');
    }

    private function openAiChat(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text]]]];
    }

    public function test_business_generate_with_openai_saved_in_settings(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-test-1234567890'])->assertOk();

        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::response($this->openAiChat('Fresh coffee every morning at Bean House! #coffee')),
        ]);

        $owner = $this->makeUser('business');
        \App\Models\Business::forceCreate(['owner_id' => $owner->id, 'company_name' => 'Bean House', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/business/campaigns/generate-content', ['platform' => 'Instagram', 'brief' => 'Promote our new coffee shop'])
            ->assertOk()
            ->assertJsonPath('content', 'Fresh coffee every morning at Bean House! #coffee')
            ->assertJsonMissingPath('detail');

        // Too short a description gets a clear message.
        $this->postJson('/api/v1/business/campaigns/generate-content', ['platform' => 'Instagram', 'brief' => 'coffee'])
            ->assertStatus(422)
            ->assertJsonPath('errors.brief.0', fn ($m) => str_contains($m, 'at least 10 characters'));
    }

    public function test_try_a_sample_post_shows_the_technical_reason(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->putJson('/api/v1/admin/ai-settings', ['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-test-1234567890'])->assertOk();

        Http::fake([
            'api.openai.com/v1/moderations' => Http::response(['results' => [['flagged' => false, 'categories' => []]]]),
            'api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'You exceeded your current quota']], 429),
        ]);

        $this->postJson('/api/v1/admin/ai-settings/try', ['brief' => 'Promote our new coffee shop'])
            ->assertStatus(422)
            ->assertJsonPath('data.detail', 'AI provider error: You exceeded your current quota');
    }
}

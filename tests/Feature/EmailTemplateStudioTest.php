<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super Admin → Email → Templates: branded defaults, custom templates,
 * image uploads, test sends, and campaigns that use a custom template.
 */
class EmailTemplateStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'superadmin'): User
    {
        return User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Yuva Admin',
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function useBrevo(): void
    {
        config(['services.brevo.key' => 'xkeysib-test']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
    }

    public function test_default_templates_use_the_branded_layout(): void
    {
        $templates = EmailTemplate::all();
        $this->assertGreaterThanOrEqual(12, $templates->count());

        foreach ($templates as $t) {
            $this->assertStringContainsString('{{logo_url}}', $t->html_body, $t->event_key . ' has the logo header');
            $this->assertStringContainsString('eBiz Network', $t->html_body, $t->event_key . ' has the footer');
            $this->assertContains('logo_url', $t->variables);
            $this->assertFalse((bool) $t->is_custom);
        }
    }

    public function test_sent_event_emails_fill_logo_and_links(): void
    {
        $this->useBrevo();
        $user = $this->makeUser('contributor');

        app(\App\Services\Email\EmailService::class)->sendEvent('task_approved', $user->email, [
            'user_name' => 'Yuva', 'task_title' => 'Follow <Acme>', 'amount' => 'USD 1.50',
        ]);

        Http::assertSent(function ($req) {
            $html = $req['htmlContent'];
            return str_contains($html, '/assets/email-logo.png')
                && str_contains($html, 'Follow &lt;Acme&gt;')
                && !str_contains($html, '{{');
        });
    }

    public function test_superadmin_creates_edits_tests_and_deletes_custom_templates(): void
    {
        $this->useBrevo();
        Sanctum::actingAs($this->makeUser());

        $tpl = $this->postJson('/api/v1/admin/email/templates', ['name' => 'Ramadan Bonus', 'subject' => 'Double rewards, {{user_name}}!'])
            ->assertCreated()
            ->assertJsonPath('data.event_key', 'custom_ramadan_bonus')
            ->assertJsonPath('data.is_custom', true)
            ->json('data');
        $this->assertStringContainsString('{{logo_url}}', $tpl['html_body'], 'New templates start from the branded layout.');

        // Same name again gets a unique key.
        $this->postJson('/api/v1/admin/email/templates', ['name' => 'Ramadan Bonus', 'subject' => 'x'])
            ->assertCreated()->assertJsonPath('data.event_key', 'custom_ramadan_bonus_2');

        $this->putJson('/api/v1/admin/email/templates/custom_ramadan_bonus', [
            'name' => 'Ramadan Bonus 2x', 'subject' => 'Double rewards', 'html_body' => '<p>Hi {{user_name}}</p>', 'text_body' => 'Hi {{user_name}}', 'is_enabled' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Ramadan Bonus 2x');

        // Test send of the unsaved draft, with sample values filled in.
        $this->postJson('/api/v1/admin/email/templates/custom_ramadan_bonus/test', [
            'to' => 'me@example.com', 'subject' => 'Draft {{amount}}', 'html_body' => '<b>{{user_name}} {{amount}}</b>',
        ])->assertOk();
        Http::assertSent(fn ($req) => $req['subject'] === '[Test] Draft USD 25.00' && $req['htmlContent'] === '<b>Yuva Admin USD 25.00</b>');

        // Built-in templates can't be deleted; custom ones can.
        $this->deleteJson('/api/v1/admin/email/templates/welcome_contributor')->assertStatus(422);
        $this->deleteJson('/api/v1/admin/email/templates/custom_ramadan_bonus')->assertOk();
        $this->assertDatabaseMissing('email_templates', ['event_key' => 'custom_ramadan_bonus']);
    }

    public function test_image_upload_returns_a_public_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser());

        $url = $this->post('/api/v1/admin/email/assets', ['image' => UploadedFile::fake()->image('banner.png', 1200, 600)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.url');
        $this->assertStringContainsString('/storage/email-assets/', $url);

        $this->post('/api/v1/admin/email/assets', ['image' => UploadedFile::fake()->create('evil.php', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->post('/api/v1/admin/email/assets', ['image' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_campaign_can_use_a_custom_template_and_keeps_unsubscribe(): void
    {
        $this->useBrevo();
        Sanctum::actingAs($this->makeUser());
        $this->makeUser('contributor');

        $this->postJson('/api/v1/admin/email/templates', ['name' => 'Promo', 'subject' => 'Promo'])->assertCreated();
        EmailTemplate::where('event_key', 'custom_promo')->update(['html_body' => '<html><body><h1>Hello {{user_name}}</h1></body></html>']);

        // Built-in templates can't be used as a campaign design.
        $this->postJson('/api/v1/admin/email/campaigns', ['name' => 'N', 'subject' => 'S', 'audience' => 'all', 'template_key' => 'welcome_contributor'])
            ->assertStatus(422)->assertJsonValidationErrors('template_key');
        // Without a template, a message is required.
        $this->postJson('/api/v1/admin/email/campaigns', ['name' => 'N', 'subject' => 'S', 'audience' => 'all'])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $id = $this->postJson('/api/v1/admin/email/campaigns', ['name' => 'N', 'subject' => 'Big news', 'audience' => 'all', 'template_key' => 'custom_promo'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/email/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.sent_count', 1);

        Http::assertSent(fn ($req) => str_contains($req['htmlContent'], '<h1>Hello Yuva</h1>')
            && str_contains($req['htmlContent'], 'Unsubscribe from marketing emails')
            && str_contains($req['htmlContent'], '/email/unsubscribe/'));
        $this->assertSame('custom_promo', EmailCampaign::find($id)->template_key);
    }
}

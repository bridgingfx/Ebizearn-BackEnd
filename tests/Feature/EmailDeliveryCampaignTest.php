<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\EmailProvider;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super Admin → Email: one-step provider apply (Brevo key + sender), which
 * provider actually sends, and marketing campaigns sent in batches through
 * that provider with a working unsubscribe link.
 */
class EmailDeliveryCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'contributor', array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($role) . ' Person',
            'email' => $role . Str::random(6) . '@example.com',
            'password' => Hash::make('V3r1fy!Strong'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $extra));
    }

    protected function applyBrevo(): void
    {
        $this->postJson('/api/v1/admin/email/apply', [
            'driver' => 'brevo',
            'secret' => 'xkeysib-test-key',
            'from_email' => 'info@ebizearn.com',
            'from_name' => 'eBizEarn',
        ])->assertOk();
    }

    public function test_superadmin_applies_brevo_and_it_becomes_the_sender(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        EmailProvider::create(['name' => 'Old SMTP', 'driver' => 'smtp', 'host' => 'mail.x.com', 'secret' => 'pw', 'from_email' => 'a@x.com', 'from_name' => 'X', 'is_active' => true]);

        $this->applyBrevo();

        $this->assertSame(1, EmailProvider::where('is_active', true)->count());
        $active = EmailProvider::where('is_active', true)->first();
        $this->assertSame('brevo', $active->driver);
        $this->assertSame('xkeysib-test-key', $active->secret);

        $this->getJson('/api/v1/admin/email/status')
            ->assertOk()
            ->assertJsonPath('data.source', 'admin')
            ->assertJsonPath('data.driver', 'brevo')
            ->assertJsonPath('data.from_email', 'info@ebizearn.com');

        // Re-applying without a key keeps the saved one (one config per driver).
        $this->postJson('/api/v1/admin/email/apply', ['driver' => 'brevo', 'from_email' => 'hello@ebizearn.com', 'from_name' => 'eBizEarn'])->assertOk();
        $this->assertSame(1, EmailProvider::where('driver', 'brevo')->count());
        $this->assertSame('xkeysib-test-key', EmailProvider::where('driver', 'brevo')->first()->secret);

        // Switching back to SMTP re-uses the saved SMTP settings.
        $this->postJson('/api/v1/admin/email/apply', ['driver' => 'smtp', 'host' => 'mail.x.com', 'from_email' => 'a@x.com', 'from_name' => 'X'])->assertOk();
        $this->assertSame('smtp', EmailProvider::where('is_active', true)->first()->driver);
    }

    public function test_apply_requires_key_for_new_provider_and_is_superadmin_only(): void
    {
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->postJson('/api/v1/admin/email/apply', ['driver' => 'brevo', 'from_email' => 'info@ebizearn.com', 'from_name' => 'X'])->assertStatus(422);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson('/api/v1/admin/email/apply', ['driver' => 'brevo', 'secret' => 'k', 'from_email' => 'info@ebizearn.com', 'from_name' => 'X'])->assertForbidden();
    }

    public function test_applied_provider_wins_over_env_key(): void
    {
        config(['services.brevo.key' => 'xkeysib-env']);
        $emails = app(EmailService::class);

        $this->assertSame('Brevo (.env)', $emails->currentProvider()->name);

        EmailProvider::create(['name' => 'Admin SMTP', 'driver' => 'smtp', 'host' => 'mail.x.com', 'secret' => 'pw', 'from_email' => 'a@x.com', 'from_name' => 'X', 'is_active' => true]);
        $this->assertSame('Admin SMTP', $emails->currentProvider()->name);
    }

    public function test_campaign_sends_in_batches_through_brevo_to_subscribed_verified_users(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->applyBrevo();

        $contributors = collect(range(1, 30))->map(fn () => $this->makeUser('contributor'));
        $this->makeUser('business');
        $this->makeUser('contributor', ['email_verified_at' => null]);
        $this->makeUser('contributor', ['marketing_unsubscribed_at' => now()]);
        $this->makeUser('contributor', ['status' => 'suspended']);

        $this->getJson('/api/v1/admin/email/campaigns/audiences')
            ->assertOk()
            ->assertJsonPath('data.contributors', 30)
            ->assertJsonPath('data.all', 31);

        $id = $this->postJson('/api/v1/admin/email/campaigns', [
            'name' => 'October promo',
            'subject' => 'New tasks for you, {{ user_name }}',
            'heading' => 'Fresh campaigns are live',
            'body' => "Hi {{ user_name }},\n\nNew verified tasks are waiting.",
            'button_label' => 'Open dashboard',
            'button_url' => 'https://ebizearn.com/contributor',
            'audience' => 'contributors',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/email/campaigns/{$id}/test", ['to' => 'me@example.com'])->assertOk();

        $first = $this->postJson("/api/v1/admin/email/campaigns/{$id}/send")->assertOk();
        $first->assertJsonPath('data.status', 'sending')->assertJsonPath('data.total_recipients', 30);

        $this->postJson("/api/v1/admin/email/campaigns/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sent_count', 30)
            ->assertJsonPath('data.failed_count', 0);

        // 1 test + 30 recipients, each through the Brevo API with the applied key.
        Http::assertSentCount(31);
        Http::assertSent(fn ($req) => $req->hasHeader('api-key', 'xkeysib-test-key')
            && str_contains($req['htmlContent'], 'Unsubscribe')
            && str_starts_with($req['subject'], 'New tasks for you, Contributor'));

        $this->assertSame($contributors->count(), EmailCampaign::find($id)->sent_count);
    }

    public function test_campaign_reports_provider_failures(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'unrecognised IP address'], 401)]);
        Sanctum::actingAs($this->makeUser('superadmin'));
        $this->applyBrevo();
        $this->makeUser('contributor');

        $id = EmailCampaign::create(['name' => 'N', 'subject' => 'S', 'body' => 'B', 'audience' => 'all', 'status' => 'draft'])->id;

        $this->postJson("/api/v1/admin/email/campaigns/{$id}/test", ['to' => 'me@example.com'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $res = $this->postJson("/api/v1/admin/email/campaigns/{$id}/send")->assertOk();
        $res->assertJsonPath('data.failed_count', 1)->assertJsonPath('data.status', 'sent');
        $this->assertStringContainsString('unrecognised IP', $res->json('data.last_error'));
    }

    public function test_unsubscribe_link_opts_user_out(): void
    {
        $user = $this->makeUser('contributor');
        $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id], null, false);

        $this->get($url)->assertOk()->assertSee('You are unsubscribed');
        $this->assertNotNull($user->fresh()->marketing_unsubscribed_at);

        $this->get('/api/v1/email/unsubscribe/' . $user->id)->assertForbidden();
    }
}

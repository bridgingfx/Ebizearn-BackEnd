<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\EmailProvider;
use App\Models\EmailTemplate;
use App\Services\Email\EmailService;
use App\Services\Email\EmailTemplateDefaults;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Super Admin: email providers, templates and delivery logs.
 */
class AdminEmailController extends Controller
{
    public function providers(): JsonResponse
    {
        return $this->ok(EmailProvider::orderByDesc('is_active')->orderBy('name')->get());
    }

    /**
     * What is sending email right now: the provider applied here, the
     * BREVO_API_KEY fallback from .env, or nothing (emails only logged).
     */
    public function status(EmailService $emails): JsonResponse
    {
        $provider = $emails->currentProvider();

        return $this->ok([
            'source' => !$provider ? 'none' : ($provider->exists ? 'admin' : 'env'),
            'provider_id' => $provider?->id,
            'name' => $provider?->name,
            'driver' => $provider?->driver,
            'from_email' => $provider?->from_email,
            'from_name' => $provider?->from_name,
            'env_brevo_key' => (string) config('services.brevo.key') !== '',
        ]);
    }

    /**
     * One-step setup from the provider picker: save the settings for the
     * chosen driver (one saved configuration per driver) and make it the
     * active sender.
     */
    public function apply(Request $request): JsonResponse
    {
        $driver = (string) $request->input('driver');
        $existing = EmailProvider::where('driver', $driver)->orderByDesc('is_active')->first();

        // A secret saved under a different APP_KEY can't be decrypted (or even
        // compared on save): drop it so the admin simply enters it again.
        if ($existing && $existing->has_secret) {
            try {
                $existing->secret;
            } catch (DecryptException) {
                $existing->setRawAttributes(array_merge($existing->getAttributes(), ['secret' => null]), true);
            }
        }

        $request->merge(['name' => $request->input('name') ?: ($existing?->name ?? self::DRIVER_NAMES[$driver] ?? 'Email provider')]);
        $data = $this->validateProvider($request, $existing);

        if (empty($data['secret'])) {
            unset($data['secret']);
        }

        $provider = DB::transaction(function () use ($existing, $data) {
            $provider = $existing ?? new EmailProvider();
            $provider->fill($data);
            $provider->is_active = true;
            if (!$provider->exists || isset($data['secret']) || $provider->isDirty(['host', 'port', 'username', 'encryption', 'region', 'from_email'])) {
                $provider->status = 'untested';
            }
            $provider->save();

            EmailProvider::where('id', '!=', $provider->id)->update(['is_active' => false]);

            return $provider;
        });

        $this->audit($request, 'email_provider.applied', $provider);

        return $this->ok($provider->fresh(), self::DRIVER_NAMES[$driver] . ' is now sending all platform emails.');
    }

    private const DRIVER_NAMES = [
        'brevo' => 'Brevo',
        'smtp' => 'SMTP',
        'sendgrid' => 'SendGrid',
        'mailgun' => 'Mailgun',
        'ses' => 'Amazon SES',
        'log' => 'Test mode (log only)',
    ];

    public function storeProvider(Request $request): JsonResponse
    {
        $provider = EmailProvider::create($this->validateProvider($request));
        $this->audit($request, 'email_provider.created', $provider);

        return $this->ok($provider, 'Email provider created.', 201);
    }

    public function updateProvider(Request $request, int $id): JsonResponse
    {
        $provider = EmailProvider::findOrFail($id);
        $data = $this->validateProvider($request, $provider);

        // A blank secret means "keep the stored one".
        if (empty($data['secret'])) {
            unset($data['secret']);
        }

        $provider->update($data);
        $this->audit($request, 'email_provider.updated', $provider);

        return $this->ok($provider->fresh(), 'Email provider updated.');
    }

    public function destroyProvider(Request $request, int $id): JsonResponse
    {
        $provider = EmailProvider::findOrFail($id);
        $this->audit($request, 'email_provider.deleted', $provider);
        $provider->delete();

        return $this->ok(null, 'Email provider deleted.');
    }

    /**
     * Make one provider the active sender (or disable it when is_active=false).
     */
    public function setActive(Request $request, int $id): JsonResponse
    {
        $provider = EmailProvider::findOrFail($id);
        $active = $request->boolean('is_active', true);

        DB::transaction(function () use ($provider, $active) {
            if ($active) {
                EmailProvider::where('id', '!=', $provider->id)->update(['is_active' => false]);
            }
            $provider->update(['is_active' => $active]);
        });

        $this->audit($request, $active ? 'email_provider.activated' : 'email_provider.disabled', $provider);

        return $this->ok($provider->fresh(), $active ? 'Provider is now active.' : 'Provider disabled.');
    }

    public function testProvider(Request $request, int $id, EmailService $emails): JsonResponse
    {
        $data = $request->validate(['to' => 'required|email']);
        $provider = EmailProvider::findOrFail($id);
        $result = $emails->sendTest($provider, $data['to']);

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['ok'] ? 'Test email sent.' : 'Test failed: ' . $result['message'],
            'data' => $provider->fresh(),
        ], $result['ok'] ? 200 : 422);
    }

    public function templates(): JsonResponse
    {
        return $this->ok(EmailTemplate::orderBy('id')->get());
    }

    public function updateTemplate(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('event_key', $key)->firstOrFail();

        $template->update($request->validate([
            'subject' => 'required|string|max:255',
            'html_body' => 'required|string',
            'text_body' => 'required|string',
            'is_enabled' => 'required|boolean',
        ]));

        $this->audit($request, 'email_template.updated', $template);

        return $this->ok($template->fresh(), 'Template saved.');
    }

    public function resetTemplate(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('event_key', $key)->firstOrFail();
        $default = EmailTemplateDefaults::find($key);
        abort_unless($default, 404);

        $template->update([
            'subject' => $default['subject'],
            'html_body' => $default['html_body'],
            'text_body' => $default['text_body'],
        ]);

        $this->audit($request, 'email_template.reset', $template);

        return $this->ok($template->fresh(), 'Template restored to default.');
    }

    public function logs(): JsonResponse
    {
        return $this->ok(EmailLog::orderByDesc('id')->limit(100)->get());
    }

    private function validateProvider(Request $request, ?EmailProvider $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'driver' => ['required', Rule::in(EmailProvider::DRIVERS)],
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'secret' => 'nullable|string|max:2000',
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'region' => 'nullable|string|max:30',
            'from_email' => 'required|email|max:255',
            'from_name' => 'required|string|max:255',
        ]);

        $driver = $data['driver'];
        $secretStored = $existing?->has_secret && ($existing->driver === $driver);

        if (in_array($driver, ['smtp', 'mailgun'], true) && empty($data['host'])) {
            abort(422, $driver === 'smtp' ? 'SMTP host is required.' : 'Mailgun domain is required.');
        }
        if ($driver !== 'log' && empty($data['secret']) && !$secretStored) {
            abort(422, 'Password / API key is required.');
        }

        return $data;
    }

    private function audit(Request $request, string $action, $entity): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function ok($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}

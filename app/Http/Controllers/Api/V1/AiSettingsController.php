<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AI\AiClient;
use App\Services\AI\AiSettings;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin → Settings → AI content generator: which AI service writes
 * campaign post content and checks it for offensive language. The API key
 * is write-only — stored encrypted, shown back only as ••••last4.
 */
class AiSettingsController extends Controller
{
    public function __construct(private AiSettings $settings)
    {
    }

    /** GET /admin/ai-settings */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->adminConfig()]);
    }

    /** PUT /admin/ai-settings { provider, model?, enabled, api_key? } — blank key keeps the saved one. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(AiSettings::PROVIDERS)],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'enabled' => 'required|boolean',
            'api_key' => 'nullable|string|min:10|max:500',
        ], ['model.regex' => 'Model names use letters, numbers, dots and dashes only (e.g. gemini-flash-latest).']);

        $before = $this->settings->adminConfig();
        $providerChanged = $data['provider'] !== $before['provider'];

        // A different provider needs its own key.
        if ($providerChanged && empty($data['api_key'])) {
            $this->settings->removeKey();
        }
        $this->settings->save($data['provider'], $data['model'] ?? null, $data['enabled'], $data['api_key'] ?? null);

        $after = $this->settings->adminConfig();
        if ($after['enabled'] && !$after['has_key']) {
            $message = 'Saved, but there is no API key yet — add one to turn AI content on.';
        } else {
            $message = $after['enabled'] ? 'AI content generator saved.' : 'Saved. AI content generation is switched off.';
        }

        // Never log the key — only that it changed.
        AuditLogger::log($request->user(), 'settings.ai_updated', SystemSetting::class, null,
            ['key_changed' => !empty($data['api_key'])], $before, $after);

        return response()->json(['success' => true, 'message' => $message, 'data' => $after]);
    }

    /** POST /admin/ai-settings/test — one short request with the saved settings. */
    public function test(AiClient $ai): JsonResponse
    {
        if (!$this->settings->apiKey()) {
            return response()->json(['success' => false, 'message' => 'Add an API key first.'], 422);
        }
        if (!$this->settings->enabled()) {
            return response()->json(['success' => false, 'message' => 'Switch AI content on first.'], 422);
        }

        $started = microtime(true);
        $result = $ai->chat('You are a connection test. Reply with exactly: OK', 'Reply with OK.', 0.0, 20);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (!$result['ok']) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . ($result['error'] ?: 'no answer') . '.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Connected to {$this->settings->provider()} ({$this->settings->model()}) in {$ms} ms.",
            'data' => ['reply' => mb_substr((string) $result['text'], 0, 50), 'ms' => $ms],
        ]);
    }

    /**
     * POST /admin/ai-settings/try { brief, platform? } — runs exactly what
     * "Generate with AI" runs and returns the technical reason on failure.
     */
    public function tryGenerate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brief' => 'required|string|min:10|max:500',
            'platform' => 'nullable|string|max:64',
        ]);

        $started = microtime(true);
        $result = app(\App\Services\AI\ContentGeneratorService::class)->generate(($data['platform'] ?? null) ?: 'instagram', $data['brief'], 'Sample Brand');
        $ms = (int) round((microtime(true) - $started) * 1000);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? "Generated in {$ms} ms." : $result['message'],
            'data' => [
                'content' => $result['content'],
                'detail' => $result['detail'] ?? null,
                'provider' => $this->settings->provider(),
                'model' => $this->settings->model(),
                'ms' => $ms,
            ],
        ], $result['success'] ? 200 : 422);
    }

    /** DELETE /admin/ai-settings/key */
    public function removeKey(Request $request): JsonResponse
    {
        $this->settings->removeKey();
        AuditLogger::log($request->user(), 'settings.ai_key_removed', SystemSetting::class, null);

        return response()->json(['success' => true, 'message' => 'API key removed.', 'data' => $this->settings->adminConfig()]);
    }
}

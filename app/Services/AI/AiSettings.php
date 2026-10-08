<?php

namespace App\Services\AI;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Which AI service writes campaign post content and checks it for
 * offensive language — set by Super Admin (Admin → Settings → AI content
 * generator). The API key is stored encrypted and never sent back to the
 * browser. Falls back to OPENAI_API_KEY from .env when nothing is saved.
 *
 * Settings live under the `ai_` keys (group "ai"), which the general
 * system-settings endpoints never list or change.
 */
class AiSettings
{
    public const PROVIDERS = ['gemini', 'openai'];

    public const DEFAULT_MODELS = [
        'gemini' => 'gemini-flash-latest',
        'openai' => 'gpt-4o-mini',
    ];

    public function provider(): string
    {
        $saved = SystemSetting::get('ai_provider');

        return in_array($saved, self::PROVIDERS, true) ? $saved : 'openai';
    }

    public function enabled(): bool
    {
        return SystemSetting::get('ai_enabled', '1') !== '0';
    }

    public function model(): string
    {
        $saved = trim((string) SystemSetting::get('ai_model', ''));

        return $saved !== '' ? $saved : self::DEFAULT_MODELS[$this->provider()];
    }

    /** Decrypted key for the chosen provider, or null when none is set. */
    public function apiKey(): ?string
    {
        $stored = SystemSetting::get('ai_api_key');
        if ($stored) {
            try {
                $key = Crypt::decryptString($stored);
                if ($key !== '') {
                    return $key;
                }
            } catch (\Throwable $e) {
                // APP_KEY changed — treat as missing.
            }
        }

        return $this->provider() === 'openai' ? (config('services.openai.key') ?: null) : null;
    }

    /** Ready to call: switched on and a key is available. */
    public function ready(): bool
    {
        return $this->enabled() && $this->apiKey() !== null;
    }

    /** Super Admin view — never the key itself. */
    public function adminConfig(): array
    {
        $key = $this->apiKey();
        $fromSettings = (bool) SystemSetting::get('ai_api_key');

        return [
            'provider' => $this->provider(),
            'model' => $this->model(),
            'default_models' => self::DEFAULT_MODELS,
            'enabled' => $this->enabled(),
            'has_key' => $key !== null,
            'key_hint' => $key ? '••••' . substr($key, -4) : null,
            'key_source' => $key === null ? 'none' : ($fromSettings ? 'settings' : 'env'),
        ];
    }

    public function save(string $provider, ?string $model, bool $enabled, ?string $apiKey): void
    {
        SystemSetting::set('ai_provider', $provider, 'ai');
        SystemSetting::set('ai_model', trim((string) $model), 'ai');
        SystemSetting::set('ai_enabled', $enabled ? '1' : '0', 'ai');
        if ($apiKey !== null && trim($apiKey) !== '') {
            SystemSetting::set('ai_api_key', Crypt::encryptString(trim($apiKey)), 'ai');
        }
    }

    public function removeKey(): void
    {
        SystemSetting::where('key', 'ai_api_key')->delete();
    }
}

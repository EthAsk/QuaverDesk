<?php

namespace App\Ai;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The customer's own AI provider details. API keys are encrypted with the
 * app key before they reach the database and are never shown again in the UI.
 */
class AiSettings
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI_COMPATIBLE = 'openai_compatible';

    public const DEFAULTS = [
        'transcription' => ['base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1', 'api_key' => null],
        'analysis' => ['provider' => self::ANTHROPIC, 'base_url' => 'https://api.openai.com/v1', 'model' => 'claude-haiku-5-5', 'api_key' => null],
    ];

    /** @return array{transcription: array, analysis: array} with keys decrypted */
    public function all(): array
    {
        $stored = Setting::get('ai', []) ?: [];
        $out = array_replace_recursive(self::DEFAULTS, $stored);

        foreach (['transcription', 'analysis'] as $part) {
            $out[$part]['api_key'] = $this->decrypt($out[$part]['api_key'] ?? null);
        }

        return $out;
    }

    public function transcriptionReady(): bool
    {
        $t = $this->all()['transcription'];

        return ! empty($t['api_key']) && ! empty($t['base_url']) && ! empty($t['model']);
    }

    public function analysisReady(): bool
    {
        $a = $this->all()['analysis'];

        return ! empty($a['api_key']) && ! empty($a['model'])
            && ($a['provider'] === self::ANTHROPIC || ! empty($a['base_url']));
    }

    /**
     * @param  array{transcription: array, analysis: array}  $new  a blank api_key keeps the stored one
     */
    public function save(array $new): void
    {
        $current = Setting::get('ai', []) ?: [];

        foreach (['transcription', 'analysis'] as $part) {
            $key = $new[$part]['api_key'] ?? null;
            $new[$part]['api_key'] = $key ? Crypt::encryptString($key) : ($current[$part]['api_key'] ?? null);
        }

        Setting::put('ai', $new);
    }

    public function forget(string $part): void
    {
        $current = Setting::get('ai', []) ?: [];
        unset($current[$part]['api_key']);
        Setting::put('ai', $current);
    }

    private function decrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null; // app key changed: the admin must re-enter the key
        }
    }
}

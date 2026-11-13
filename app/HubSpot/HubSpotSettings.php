<?php

namespace App\HubSpot;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** The company's HubSpot private app token (encrypted, never shown again) and what to log. */
class HubSpotSettings
{
    public const DEFAULTS = ['log_calls' => true, 'include_summary' => true, 'include_recording_link' => true, 'create_contacts' => false, 'lookup_names' => true];

    /** @return array{token: ?string, log_calls: bool, include_summary: bool, include_recording_link: bool, create_contacts: bool, lookup_names: bool} */
    public function all(): array
    {
        $out = array_replace(self::DEFAULTS, ['token' => null], Setting::get('hubspot', []) ?: []);
        try {
            $out['token'] = $out['token'] ? Crypt::decryptString($out['token']) : null;
        } catch (DecryptException) {
            $out['token'] = null;
        }

        return $out;
    }

    public function token(): ?string
    {
        return $this->all()['token'];
    }

    /** Logging calls is on: there's a token and it hasn't been switched off. */
    public function active(): bool
    {
        $a = $this->all();

        return $a['token'] !== null && $a['log_calls'];
    }

    /** A blank token keeps the stored one. */
    public function save(array $options, ?string $token): void
    {
        $current = Setting::get('hubspot', []) ?: [];
        $options['token'] = $token ? Crypt::encryptString($token) : ($current['token'] ?? null);
        Setting::put('hubspot', array_replace(self::DEFAULTS, $options));
    }

    public function forgetToken(): void
    {
        $current = Setting::get('hubspot', []) ?: [];
        unset($current['token']);
        Setting::put('hubspot', $current);
    }
}

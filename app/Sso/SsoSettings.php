<?php

namespace App\Sso;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** A company's single sign-on set-up: which providers, with their own client id and (encrypted) secret. */
class SsoSettings
{
    public const PROVIDERS = ['microsoft' => 'Microsoft', 'google' => 'Google'];

    /**
     * Microsoft: 'directory' is the Entra directory (tenant) id. Google: 'domain' is the Workspace domain.
     *
     * @return array{enabled: bool, client_id: string, client_secret: ?string, restrict: string}
     */
    public function get(string $provider): array
    {
        $stored = (Setting::get('sso', []) ?: [])[$provider] ?? [];
        $out = array_replace(['enabled' => false, 'client_id' => '', 'client_secret' => null, 'restrict' => ''], $stored);
        try {
            $out['client_secret'] = $out['client_secret'] ? Crypt::decryptString($out['client_secret']) : null;
        } catch (DecryptException) {
            $out['client_secret'] = null;
        }

        return $out;
    }

    /** Ready to use: switched on and complete. */
    public function ready(string $provider): bool
    {
        $c = $this->get($provider);

        return isset(self::PROVIDERS[$provider]) && $c['enabled'] && $c['client_id'] !== '' && $c['client_secret'] !== null && $c['restrict'] !== '';
    }

    /** @return list<string> */
    public function readyProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn ($p) => $this->ready($p)));
    }

    /** A blank secret keeps the stored one. */
    public function save(string $provider, bool $enabled, string $clientId, ?string $secret, string $restrict): void
    {
        $all = Setting::get('sso', []) ?: [];
        $all[$provider] = [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $secret ? Crypt::encryptString($secret) : ($all[$provider]['client_secret'] ?? null),
            'restrict' => strtolower($restrict),
        ];
        Setting::put('sso', $all);
    }
}

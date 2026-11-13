<?php

namespace App\Sso;

use App\Models\Setting;
use Illuminate\Support\Str;

/** The secret a company's directory (Entra, Okta…) sends to push people here. Only its hash is kept. */
class ScimToken
{
    /** Makes a new token (replacing any old one) and returns it: this is the only time it can be seen. */
    public function make(): string
    {
        $token = 'qdscim_'.Str::random(48);
        Setting::put('scim', ['hash' => hash('sha256', $token), 'created_at' => now()->toIso8601String()]);

        return $token;
    }

    public function valid(?string $token): bool
    {
        $hash = (Setting::get('scim')['hash'] ?? null);

        return $token !== null && $token !== '' && is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    public function exists(): bool
    {
        return (Setting::get('scim')['hash'] ?? null) !== null;
    }

    public function createdAt(): ?string
    {
        return Setting::get('scim')['created_at'] ?? null;
    }

    public function revoke(): void
    {
        Setting::forget('scim');
    }
}

<?php

namespace App\Voice;

/**
 * Addresses the voice server fetches (prompts, sounds). Always on the app
 * address the voice server is configured with, never a company's own web
 * address, whichever request or job makes them.
 */
final class VoiceUrl
{
    /** @param array<string, mixed> $parameters */
    public static function to(string $route, array $parameters): string
    {
        $root = config('voip.freeswitch.app_url') ?: config('app.url');

        return rtrim((string) $root, '/').route($route, $parameters, false);
    }
}

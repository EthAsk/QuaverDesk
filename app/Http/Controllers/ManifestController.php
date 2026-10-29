<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Web app manifest: lets browsers install the phone as an app. */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'id' => '/phone',
            'name' => "{$name} Phone",
            'short_name' => $name,
            'description' => 'Make and take calls on your business line.',
            'start_url' => '/phone',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0f1420',
            'theme_color' => '#14213d',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Opening the app again (or a tel: link) brings the running phone forward instead of a second one.
            'launch_handler' => ['client_mode' => ['focus-existing', 'auto']],
            // Phone number links anywhere on the computer open in the phone, ready to call.
            'protocol_handlers' => [['protocol' => 'tel', 'url' => '/phone?dial=%s']],
            'shortcuts' => [
                ['name' => 'Call history', 'url' => '/calls'],
                ['name' => 'Voicemail', 'url' => '/recordings'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }
}

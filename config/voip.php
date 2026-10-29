<?php

return [

    /*
    | Public half of the licensor's Ed25519 key. Installs verify paid licence
    | keys against it. It is built in (LicenceCodec::PUBLIC_KEY); the
    | LICENCE_PUBLIC_KEY setting only applies on development machines. The
    | secret half is NEVER stored in this repo; only the licensor uses it.
    */
    'licence_public_key' => App\Domain\Licensing\LicenceCodec::trustedPublicKey(env('APP_ENV'), env('LICENCE_PUBLIC_KEY')),

    /*
    | Bump this when the operator acknowledgements (emergency calls, recording
    | law, fraud, AI data) change. Admins must re-accept before calling resumes.
    */
    'terms_version' => '2026-10-29',

    // Comma-separated proxy IPs/CIDRs in front of the app (see DEPLOYMENT.md).
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Allow the very first owner account to be created from the web page.
    | Safer: set to false in production and use `php artisan user:create-owner`,
    | so nobody can claim a fresh public install before you do.
    */
    'web_setup' => (bool) env('ALLOW_WEB_SETUP', true),

    /*
    | Our own voice server (FreeSWITCH). It asks this app what to do at each
    | step of a call (mod_httapi) and reads phone, trunk and allow-list config
    | that this app writes into conf_dir. See docs/VOICE-SERVER.md.
    */
    'freeswitch' => [
        // SIP domain phones and softphones sign in to, e.g. voice.example.co.uk.
        'domain' => env('VOICE_DOMAIN'),
        // Shared secret FreeSWITCH sends (HTTP Basic auth) on every webhook.
        'webhook_secret' => env('VOICE_WEBHOOK_SECRET'),
        // How FreeSWITCH reaches this app (defaults to APP_URL), e.g. http://127.0.0.1:8000 on one server.
        'app_url' => env('VOICE_APP_URL'),
        // Where this app writes generated FreeSWITCH config (directory, gateways, ACL).
        'conf_dir' => env('VOICE_CONF_DIR'),
        // The browser softphone connects here (wss:// in production).
        'websocket_url' => env('VOICE_WEBSOCKET_URL'),
        // Public IP address for SIP and audio when the server is behind NAT. Empty to detect it.
        'external_ip' => env('VOICE_EXTERNAL_IP'),
        // TLS for desk phones (5061) and wss:// for browsers (7443). Needs certificates; off only for local testing.
        'tls' => (bool) env('VOICE_TLS', true),
        // Public address trunk providers send calls to, shown in the trunk wizard.
        'trunk_address' => env('VOICE_TRUNK_ADDRESS'),
        // Event socket, used to reload config after changes. Keep it on localhost.
        'esl_host' => env('VOICE_ESL_HOST', '127.0.0.1'),
        'esl_port' => (int) env('VOICE_ESL_PORT', 8021),
        'esl_password' => env('VOICE_ESL_PASSWORD'),
        // Public STUN server for browsers behind NAT. Empty to disable.
        'stun_url' => env('VOICE_STUN_URL', 'stun:stun.l.google.com:19302'),
    ],

    // The number shown to people you call when no caller ID is set under Calling rules.
    'caller_id' => env('VOICE_CALLER_ID'),

    // Spoken prompts. "kokoro" (most natural) and "piper" are neural voices that run
    // on this server; "freeswitch" is FreeSWITCH's built-in robotic voice; "command"
    // runs any other text-to-speech program. Admins can switch voice under Voice.
    'tts' => [
        'driver' => env('TTS_DRIVER', 'freeswitch'),
        'piper' => [
            'binary' => env('TTS_PIPER_BINARY', 'piper'),
            // Path to a Piper voice model (.onnx, with its .onnx.json beside it), e.g. en_GB-cori-high.onnx
            'voice' => env('TTS_PIPER_VOICE'),
            // Folder of voices admins can choose from under Voice (default: the folder TTS_PIPER_VOICE is in).
            'voices' => env('TTS_PIPER_VOICES'),
        ],
        // Kokoro: the most natural voices. Needs Python with kokoro-onnx (see docs/VOICE-SERVER.md).
        'kokoro' => [
            'python' => env('TTS_KOKORO_PYTHON', 'python3'),
            // Folder holding kokoro-v1.0.onnx and voices-v1.0.bin
            'models' => env('TTS_KOKORO_MODELS'),
            'voice' => env('TTS_KOKORO_VOICE', 'bf_emma'),
        ],
        // Speaking pace, 0.7 (slower) to 1.3 (faster). Admins can change it under Voice.
        'speed' => (float) env('TTS_SPEED', 1.0),
        'engine' => env('TTS_ENGINE', 'flite'),
        'voice' => env('TTS_VOICE', 'slt'),
        // JSON array of arguments. {out} is replaced by the output path; the text is sent on stdin.
        'command' => json_decode((string) env('TTS_COMMAND', '[]'), true) ?: [],
    ],

    // ffmpeg converts uploaded greetings and hold music.
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
];

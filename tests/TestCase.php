<?php

namespace Tests;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Models\Acknowledgement;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function acknowledgeTerms(User $by): void
    {
        Acknowledgement::create([
            'user_id' => $by->id,
            'version' => config('voip.terms_version'),
            'items' => ['test' => true],
            'accepted_at' => now(),
        ]);
    }

    /** Installs a genuine signed licence for the given plan. */
    protected function usePlan(Plan $plan, int $seats = 25): void
    {
        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        Setting::put('licence_key', LicenceCodec::issue($keys['secret'], 'Test Ltd', $plan, $seats, null));
        $this->app->forgetScopedInstances();
    }

    /** Posts as FreeSWITCH's httapi does: form fields plus the shared secret (HTTP Basic auth). */
    protected function voicePost(string $uri, array $params = [], array $files = [])
    {
        $server = ['PHP_AUTH_USER' => 'freeswitch', 'PHP_AUTH_PW' => config('voip.freeswitch.webhook_secret')];

        return $this->call('POST', $uri, $params, [], $files, $server);
    }

    /** A FreeSWITCH call id (UUID) for a short test name. */
    protected static function callId(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /** A tiny valid WAV file: $seconds of silence, 8 kHz 16-bit mono. */
    protected static function wav(int $seconds): string
    {
        $data = str_repeat("\0", 16000 * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}

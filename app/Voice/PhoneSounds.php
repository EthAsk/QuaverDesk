<?php

namespace App\Voice;

use App\Http\Controllers\Admin\SoundController;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Models\Sound;
use App\Tenancy\Quotas;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Turns audio recorded on a phone (*0, or a greeting under *00) into a sound in the library. */
class PhoneSounds
{
    public function __construct(
        private readonly Audio $audio,
        private readonly Quotas $quotas,
    ) {
    }

    /** Once per call: the voice server can send the recording again as the call ends. */
    public function save(CallRequest $call, string $name): ?Sound
    {
        $upload = $call->recording();
        if ($upload === null || VoiceController::wavSeconds((string) $upload->getRealPath()) < 1 || ! Cache::add('sound-recorded:'.$call->callId(), true, now()->addDay())) {
            return null;
        }

        $file = bin2hex(random_bytes(20));
        $path = Sound::pathFor($file);
        @mkdir(dirname($path), 0750, true);

        try {
            $seconds = $this->audio->import((string) $upload->getRealPath(), 'audio/wav', $path, SoundController::MAX_SECONDS);
        } catch (Throwable $e) {
            @unlink($path);
            Log::warning('Phone recording could not be converted.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($this->quotas->storageFull((int) filesize($path))) {
            @unlink($path);

            return null;
        }

        $sound = Sound::query()->create(['name' => $name, 'file' => $file, 'seconds' => $seconds]);
        $sound->forceFill(['bytes' => (int) filesize($path)])->save();

        return $sound;
    }

}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncVoiceServer;
use App\Models\Setting;
use App\Models\Sound;
use App\Tenancy\Quotas;
use App\Tenancy\TenantRule;
use App\Voice\Audio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** The business's own audio: recorded greetings, menu messages and hold music. */
class SoundController extends Controller
{
    public const MAX_KB = 20 * 1024;
    public const MAX_SECONDS = 600;

    public function index(): View
    {
        return view('admin.sounds', [
            'sounds' => Sound::query()->with(['mailboxes:id,name,greeting_sound_id', 'ivrMenus:id,name,greeting_sound_id'])->orderBy('name')->get(),
            'holdMusicId' => Sound::holdMusic()?->id,
        ]);
    }

    public function store(Request $request, Audio $audio, Quotas $quotas): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'audio' => ['required', 'file', 'max:'.self::MAX_KB],
        ]);

        $upload = $request->file('audio');
        $mime = (string) $upload->getMimeType(); // from the file's contents, not its name
        if (! isset(Audio::FORMATS[$mime])) {
            throw ValidationException::withMessages(['audio' => 'Use an MP3, WAV, M4A, OGG, FLAC or WebM audio file.']);
        }

        $file = bin2hex(random_bytes(20));
        $path = Sound::pathFor($file);
        @mkdir(dirname($path), 0750, true);

        try {
            $seconds = $audio->import($upload->getRealPath(), $mime, $path, self::MAX_SECONDS);
        } catch (Throwable $e) {
            @unlink($path);
            Log::warning('Sound upload could not be converted.', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['audio' => 'That file could not be read as audio. Try exporting it as an MP3 or WAV.']);
        }

        if ($quotas->storageFull((int) filesize($path))) {
            @unlink($path);

            throw ValidationException::withMessages(['audio' => 'Your storage is full. Delete sounds or recordings you no longer need, or ask your provider for more.']);
        }

        $sound = Sound::query()->create(['name' => trim($data['name']), 'file' => $file, 'seconds' => $seconds]);
        $sound->forceFill(['bytes' => (int) filesize($path)])->save();

        if ($request->expectsJson()) {
            return response()->json(['id' => $sound->id, 'redirect' => route('admin.sounds')]);
        }

        return back()->with('status', "\"{$sound->name}\" added ({$sound->length()}).");
    }

    public function update(Request $request, Sound $sound): RedirectResponse
    {
        $sound->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return back()->with('status', 'Renamed.');
    }

    public function destroy(Sound $sound): RedirectResponse
    {
        $wasHoldMusic = Sound::holdMusic()?->is($sound);
        $sound->delete();

        if ($wasHoldMusic) {
            Setting::forget('sounds.hold_music');
            SyncVoiceServer::dispatch();
        }

        return back()->with('status', "\"{$sound->name}\" deleted. Anything that used it reads its text aloud again.");
    }

    public function holdMusic(Request $request): RedirectResponse
    {
        $data = $request->validate(['sound_id' => ['nullable', 'integer', TenantRule::exists('sounds')]]);

        if (empty($data['sound_id'])) {
            Setting::forget('sounds.hold_music');
        } else {
            Setting::put('sounds.hold_music', (int) $data['sound_id']);
        }
        SyncVoiceServer::dispatch();

        return back()->with('status', empty($data['sound_id']) ? 'Hold music turned off.' : 'Hold music saved. It plays on the next call put on hold.');
    }
}

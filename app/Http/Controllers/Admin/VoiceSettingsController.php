<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WarmPrompts;
use App\Models\Setting;
use App\Voice\Audio;
use App\Voice\Prompts;
use App\Voice\Voices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Choose the voice that reads greetings and messages, after hearing each one. */
class VoiceSettingsController extends Controller
{
    public const SAMPLE = 'Thank you for calling. Press 1 for sales, or 2 for support. Or stay on the line and we will put you through.';

    public function show(Voices $voices): View
    {
        return view('admin.voice', [
            'voices' => $voices->available(),
            'current' => $voices->current(),
            'speed' => $voices->speed(),
            'sample' => self::SAMPLE,
        ]);
    }

    public function update(Request $request, Voices $voices): RedirectResponse
    {
        $data = $request->validate([
            'voice' => ['required', 'string', Rule::in(array_keys($voices->available()))],
            'speed' => ['required', 'numeric', 'min:0.7', 'max:1.3'],
        ]);

        Setting::put('tts.voice', $data['voice']);
        Setting::put('tts.speed', round((float) $data['speed'], 2));
        WarmPrompts::dispatch(); // every greeting and message, in the new voice

        return back()->with('status', 'Voice saved. Greetings and messages are being made in the new voice; calls use it as soon as each is ready.');
    }

    /** The sample read in one voice, as clear audio or as it sounds on a phone line. */
    public function preview(Request $request, Voices $voices, Audio $audio): BinaryFileResponse
    {
        $data = $request->validate([
            'voice' => ['required', 'string', Rule::in(array_keys($voices->available()))],
            'speed' => ['required', 'numeric', 'min:0.7', 'max:1.3'],
            'text' => ['required', 'string', 'max:400'],
            'line' => ['required', Rule::in(['clear', 'phone'])],
        ]);

        $prompts = new Prompts($voices->config($data['voice'], (float) $data['speed']));
        abort_unless($prompts->makesFiles(), 422, 'The built-in voice can only be heard on a call.');

        try {
            $path = $prompts->file(trim($data['text']));
            if ($data['line'] === 'phone') {
                $phone = substr($path, 0, -4).'.phone.wav';
                if (! is_file($phone)) {
                    $audio->phoneLine($path, $phone);
                }
                $path = $phone;
            }
        } catch (Throwable $e) {
            Log::error('Voice preview failed.', ['voice' => $data['voice'], 'error' => $e->getMessage()]);
            abort(500, 'That voice could not be made on this server. Check the TTS settings in docs/VOICE-SERVER.md.');
        }

        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=3600']);
    }
}

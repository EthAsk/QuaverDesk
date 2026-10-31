<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Support\RecordingStore;
use App\Tenancy\Tenancy;
use App\Voice\VoicemailAudio;
use Illuminate\Http\Response;

/** A voicemail's decrypted audio for the voice server, at a signed address that expires (see VoicemailAudio). */
class VoicemailAudioController extends Controller
{
    public function __invoke(string $file, RecordingStore $store, Tenancy $tenancy): Response
    {
        $id = VoicemailAudio::verify($file);
        abort_if($id === null, 404);

        // The voice server fetches from the app's own address, not a company's: the signature says which message.
        $message = $tenancy->everywhere(fn () => Recording::query()->where('kind', Recording::VOICEMAIL)->find($id));
        $audio = $message ? $store->get($message) : null;
        abort_if($audio === null, 404);

        return response($audio, 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'no-store, private']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Ai\AiSettings;
use App\Jobs\AnalyseRecording;
use App\Models\Recording;
use App\Support\RecordingPolicy;
use App\Support\RecordingStore;
use App\Voice\Audio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class RecordingController extends Controller
{
    public function index(Request $request): View
    {
        $kind = $request->query('kind');

        $recordings = Recording::query()
            ->visibleTo($request->user())
            ->when(in_array($kind, [Recording::CALL, Recording::VOICEMAIL], true), fn ($q) => $q->where('kind', $kind))
            ->with(['mailbox:id,name', 'call.user:id,name'])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('recordings.index', ['recordings' => $recordings, 'kind' => $kind]);
    }

    public function show(Request $request, Recording $recording, RecordingPolicy $policy, AiSettings $ai): View
    {
        $this->authorizeView($request, $recording);

        return view('recordings.show', [
            'recording' => $recording->load(['mailbox', 'call.user']),
            'features' => $policy->aiFeatures($recording),
            'aiReady' => $ai->transcriptionReady(),
        ]);
    }

    /** Streams decrypted audio to an authorised user; never cached by the browser. */
    public function audio(Request $request, Recording $recording, RecordingStore $store): Response
    {
        $this->authorizeView($request, $recording);

        $audio = $store->get($recording);
        abort_if($audio === null, 404);

        [$type, $ext] = Audio::typeOf($audio); // call recordings are .m4a, voicemail .wav

        return response($audio, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="recording-'.$recording->id.'.'.$ext.'"',
        ]);
    }

    /** The paid "Transcribe & summarise" button. */
    public function analyse(Request $request, Recording $recording, RecordingPolicy $policy, AiSettings $ai): RedirectResponse
    {
        $this->authorizeView($request, $recording);

        if (! $policy->aiAllowed($recording)) {
            return back()->with('upgrade', 'AI transcripts and summaries');
        }
        if (! $ai->transcriptionReady()) {
            return back()->withErrors(['ai' => 'Connect your AI provider first (Admin → AI provider).']);
        }
        if (! $recording->hasAudio() && ! $recording->transcript) {
            return back()->withErrors(['ai' => 'The audio for this recording is no longer stored.']);
        }

        $recording->forceFill(['ai_status' => 'queued', 'ai_error' => null])->save();
        AnalyseRecording::dispatch($recording->id);

        return back()->with('status', 'Sent to your AI provider. Refresh in a minute to see the result.');
    }

    public function destroy(Request $request, Recording $recording, RecordingStore $store): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $store->deleteAudio($recording);
        $recording->delete();

        return redirect()->route('recordings')->with('status', 'Recording and its transcript deleted.');
    }

    private function authorizeView(Request $request, Recording $recording): void
    {
        abort_unless(Recording::query()->visibleTo($request->user())->whereKey($recording->id)->exists(), 404);
    }
}

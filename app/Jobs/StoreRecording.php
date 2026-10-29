<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Support\RecordingPolicy;
use App\Support\RecordingStore;
use App\Voice\Audio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Encrypts an uploaded recording, removes the plain upload, then hands on to AI and email. */
class StoreRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $recordingId)
    {
    }

    public function handle(RecordingStore $store, RecordingPolicy $policy, Audio $audio): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording === null || $recording->status !== 'pending') {
            return;
        }

        $disk = Storage::disk('local');
        $upload = (string) $recording->upload_path;
        if ($upload === '' || ! $disk->exists($upload)) {
            throw new RuntimeException('The uploaded recording is missing.');
        }

        $store->put($recording, $this->audioFor($recording, $disk->path($upload), $audio));
        $disk->delete($upload);
        $recording->forceFill(['upload_path' => null, 'expires_at' => now()->addDays($policy->retentionDays())])->save();

        $this->next($recording, $policy);
    }

    /**
     * Call recordings can last hours, so they are compressed (about 12 times smaller)
     * before being encrypted. Voicemail stays as WAV for email attachments. If
     * compression fails, the WAV is kept: a recording is never lost over it.
     */
    private function audioFor(Recording $recording, string $wav, Audio $audio): string
    {
        if ($recording->kind !== 'call') {
            return (string) file_get_contents($wav);
        }

        $m4a = $wav.'.m4a';
        try {
            $audio->compressCall($wav, $m4a);

            return (string) file_get_contents($m4a);
        } catch (Throwable $e) {
            Log::warning('Call recording kept as WAV: compression failed.', ['recording' => $recording->id, 'error' => $e->getMessage()]);

            return (string) file_get_contents($wav);
        } finally {
            @unlink($m4a);
        }
    }

    public function failed(Throwable $e): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording === null) {
            return;
        }

        Log::error('Recording could not be stored.', ['recording' => $recording->id, 'error' => $e->getMessage()]);
        $recording->forceFill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)])->save();

        // Still tell people a voicemail arrived, even without the audio.
        if ($recording->kind === Recording::VOICEMAIL) {
            SendVoicemailEmail::dispatch($recording->id);
        }
    }

    private function next(Recording $recording, RecordingPolicy $policy): void
    {
        $ai = $policy->autoAi() && $policy->aiAllowed($recording);
        $voicemail = $recording->kind === Recording::VOICEMAIL;

        if ($ai) {
            $recording->forceFill(['ai_status' => 'queued'])->save();
            AnalyseRecording::dispatch($recording->id, thenEmail: $voicemail);
        } elseif ($voicemail) {
            SendVoicemailEmail::dispatch($recording->id);
        }
    }
}

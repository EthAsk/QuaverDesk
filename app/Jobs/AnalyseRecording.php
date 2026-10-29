<?php

namespace App\Jobs;

use App\Ai\AiClient;
use App\Jobs\Middleware\TakeTurns;
use App\Models\Recording;
use App\Support\RecordingPolicy;
use App\Support\RecordingStore;
use App\Voice\Audio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends a recording to the customer's own AI provider and stores the results
 * (encrypted). Only produces what the licence allows. A failure is recorded
 * on the recording and never blocks the voicemail email.
 */
class AnalyseRecording implements ShouldQueue
{
    use Queueable;

    /** No retry after a failure (the person can ask again), but it may wait its turn for up to a day. */
    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 300;

    public function __construct(public readonly int $recordingId, public readonly bool $thenEmail = false)
    {
    }

    public function middleware(): array
    {
        return [new TakeTurns()];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(AiClient $ai, RecordingStore $store, RecordingPolicy $policy): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording === null) {
            return;
        }

        try {
            $features = $policy->aiFeatures($recording);
            if (! $features['transcript']) {
                throw new RuntimeException('AI features for this recording are not included in your plan.');
            }

            $transcript = $recording->transcript;
            if (! $transcript) {
                $audio = $store->get($recording) ?? throw new RuntimeException('The audio is no longer available.');
                $transcript = $ai->transcribe($audio, 'recording-'.$recording->id.'.'.Audio::typeOf($audio)[1]);
                $recording->forceFill(['transcript' => $transcript])->save();
            }

            if ($transcript !== '' && ($features['summary'] || $features['actionItems'] || $features['sentiment'])) {
                $analysis = $ai->analyse(
                    $transcript,
                    $features['summary'],
                    $features['actionItems'],
                    $features['sentiment'],
                    $recording->kind,
                );
                $recording->forceFill([
                    'summary' => $analysis->summary,
                    'action_items' => $analysis->actionItems,
                    'sentiment' => $analysis->sentiment,
                ]);
            }

            $recording->forceFill(['ai_status' => 'done', 'ai_error' => null])->save();
        } catch (Throwable $e) {
            Log::warning('AI processing failed.', ['recording' => $recording->id, 'error' => $e->getMessage()]);
            $recording->forceFill(['ai_status' => 'failed', 'ai_error' => substr($e->getMessage(), 0, 1000)])->save();
        } finally {
            if ($this->thenEmail) {
                SendVoicemailEmail::dispatch($recording->id);
            }
        }
    }
}

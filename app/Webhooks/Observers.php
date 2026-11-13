<?php

namespace App\Webhooks;

use App\Models\Call;
use App\Models\Recording;

/** Watches calls and recordings, and turns what happens to them into webhook events. */
final class Observers
{
    public static function register(): void
    {
        Call::created(function (Call $call) {
            if (self::counts($call)) {
                Webhooks::emit(Webhooks::CALL_STARTED, $call, Webhooks::callData($call));
            }
        });

        Call::updated(function (Call $call) {
            if (self::counts($call) && $call->wasChanged('duration_seconds') && $call->getOriginal('duration_seconds') === null) {
                Webhooks::emit(Webhooks::CALL_ENDED, $call, Webhooks::callData($call));
            }
        });

        Recording::created(function (Recording $recording) {
            // What the AI receptionist wrote down has no audio to wait for.
            if ($recording->status === Recording::MESSAGE) {
                Webhooks::emit(Webhooks::VOICEMAIL_RECEIVED, $recording, Webhooks::recordingData($recording));
            }
        });

        Recording::updated(function (Recording $recording) {
            if ($recording->wasChanged('status') && $recording->status === 'stored') {
                $recording->kind === Recording::VOICEMAIL
                    ? Webhooks::emit(Webhooks::VOICEMAIL_RECEIVED, $recording, Webhooks::recordingData($recording))
                    : Webhooks::emit(Webhooks::RECORDING_READY, $recording, Webhooks::recordingData($recording));
            }
            if ($recording->wasChanged('ai_status') && $recording->ai_status === 'done') {
                Webhooks::emit(Webhooks::AI_SUMMARY_READY, $recording, Webhooks::recordingData($recording, withAi: true));
            }
        });
    }

    /** Transfers and follow-me rings are parts of a call already reported, not calls of their own. */
    private static function counts(Call $call): bool
    {
        return ! in_array($call->leg, [Call::LEG_TRANSFER, Call::LEG_FOLLOW_ME], true);
    }
}

<?php

namespace App\Jobs;

use App\Mail\VoicemailReceived;
use App\Models\Recording;
use App\Support\RecordingStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendVoicemailEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $recordingId)
    {
    }

    public function handle(RecordingStore $store): void
    {
        $recording = Recording::query()->with('mailbox')->find($this->recordingId);
        if ($recording === null || $recording->emailed_at !== null || $recording->mailbox === null) {
            return;
        }

        $mailbox = $recording->mailbox;
        $recipients = $mailbox->recipientEmails();

        if ($recipients !== []) {
            $audio = $mailbox->attach_audio ? $store->get($recording) : null;
            // Sent directly (not queued again) so audio never sits in the queue table.
            Mail::to($recipients)->send(new VoicemailReceived($recording, $mailbox, $audio));
        }

        $recording->forceFill(['emailed_at' => now()])->save();

        if ($mailbox->delete_after_send && $recording->hasAudio()) {
            $store->deleteAudio($recording);
        }
    }
}

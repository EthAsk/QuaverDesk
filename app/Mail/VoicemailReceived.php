<?php

namespace App\Mail;

use App\Models\Mailbox;
use App\Models\Recording;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VoicemailReceived extends Mailable
{
    public function __construct(
        public readonly Recording $recording,
        public readonly Mailbox $mailbox,
        private readonly ?string $audio,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = $this->recording->from ?: 'an unknown number';

        return new Envelope(subject: "Voicemail from {$from} ({$this->mailbox->name})");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.voicemail', with: [
            'recording' => $this->recording,
            'mailbox' => $this->mailbox,
            'showText' => $this->mailbox->include_transcript,
            'hasAudio' => $this->audio !== null,
            'link' => route('recordings.show', $this->recording),
        ]);
    }

    public function attachments(): array
    {
        if ($this->audio === null) {
            return [];
        }

        $audio = $this->audio;

        return [
            Attachment::fromData(fn () => $audio, 'voicemail-'.$this->recording->created_at->format('Y-m-d-His').'.wav')
                ->withMime('audio/wav'),
        ];
    }
}

<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells people about changes to how they sign in, so they notice ones they didn't make. */
class SecurityAlert extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly string $what,
        public readonly ?string $ip = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': '.$this->what);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.security-alert', with: [
            'user' => $this->user,
            'what' => $this->what,
            'ip' => $this->ip,
            'when' => now()->timezone('Europe/London')->format('D j M Y, H:i'),
            'link' => route('security'),
        ]);
    }
}

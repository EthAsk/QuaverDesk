<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Something about calls placed over the API that an admin should see. */
class ApiCallAlert extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $what)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': '.ucfirst($this->what));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.api-call-alert', with: ['what' => $this->what, 'link' => route('admin.api'), 'when' => now()->timezone('Europe/London')->format('D j M Y, H:i')]);
    }
}

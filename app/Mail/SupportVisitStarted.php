<?php

namespace App\Mail;

use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a company's owners that QuaverDesk support has signed in to their account, and why. */
class SupportVisitStarted extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $owner,
        public readonly SupportSession $visit,
        public readonly User $as,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': support has signed in to your account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-visit', with: [
            'owner' => $this->owner,
            'as' => $this->as,
            'operator' => $this->visit->operator?->name ?? 'QuaverDesk support',
            'reason' => $this->visit->reason,
            'from' => $this->visit->started_at?->timezone('Europe/London')->format('D j M Y, H:i'),
            'until' => $this->visit->ends_at?->timezone('Europe/London')->format('H:i'),
        ]);
    }
}

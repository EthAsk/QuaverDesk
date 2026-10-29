<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells admins an outside call was refused by a toll-fraud limit: either busy day, or a hijacked account. */
class CallLimitReached extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $admin,
        public readonly User $caller,
        public readonly string $limit,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': call limit reached by '.$this->caller->name);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.call-limit', with: [
            'admin' => $this->admin,
            'caller' => $this->caller,
            'limit' => $this->limit,
            'when' => now()->timezone('Europe/London')->format('D j M Y, H:i'),
            'calls' => route('calls'),
            'rules' => route('admin.calling-rules'),
        ]);
    }
}

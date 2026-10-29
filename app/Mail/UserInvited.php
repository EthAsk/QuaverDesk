<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Invites;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UserInvited extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly string $link,
        public readonly ?string $invitedBy,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' phone account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invite', with: [
            'user' => $this->user,
            'link' => $this->link,
            'invitedBy' => $this->invitedBy,
            'days' => Invites::DAYS,
        ]);
    }
}

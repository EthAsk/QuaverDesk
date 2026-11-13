<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The weekly or monthly call report, with the calls attached as a spreadsheet. */
class ReportEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $period,
        public readonly string $label,
        public readonly array $summary,
        public readonly array $queues,
        public readonly string $csv,
        public readonly string $link,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name')." {$this->period} call report: {$this->label}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.report', with: ['period' => $this->period, 'label' => $this->label, 'summary' => $this->summary, 'queues' => $this->queues, 'link' => $this->link]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->csv, 'calls.csv')->withMime('text/csv')];
    }
}

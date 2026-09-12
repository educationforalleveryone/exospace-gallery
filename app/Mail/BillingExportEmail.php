<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BillingExportEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $summary,
        public array $csv,
        public array $window,
    ) {}

    public function envelope(): Envelope
    {
        $moneyEvents = $this->summary['refunded'] + $this->summary['partial_refund'] + $this->summary['chargeback'];

        return new Envelope(
            subject: sprintf(
                '[Exospace] Weekly billing digest — %d money event%s, %d sale%s (%s → %s)',
                $moneyEvents,
                $moneyEvents === 1 ? '' : 's',
                $this->summary['completed'],
                $this->summary['completed'] === 1 ? '' : 's',
                $this->window['from'],
                $this->window['to'],
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing-export',
            text: 'emails.billing-export-text',
        );
    }

    public function attachments(): array
    {
        if (($this->csv['count'] ?? 0) === 0) {
            return [];
        }

        return [
            Attachment::fromData(
                fn () => $this->csv['content'],
                $this->csv['filename'],
            )->withMime('text/csv'),
        ];
    }
}

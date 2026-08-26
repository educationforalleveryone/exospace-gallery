<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ITERATION 6 — weekly billing digest email (scheduled export).
 *
 * Sent by exospace:send-billing-export (Monday 07:00) to every address in
 * BILLING_EXPORT_EMAIL. The digest exists so finance reconciliation runs
 * on a predictable cadence instead of depending on someone remembering
 * the on-demand Billing Review export: trailing-7-day money events as a
 * CSV attachment (same columns as the on-demand export — byte-identical
 * code path via BillingExportService) plus a summary of the week and the
 * failed-webhook count.
 *
 * Zero-row weeks still send — a predictable "no money events this week"
 * is itself reconciliation evidence; a missing email is a signal.
 *
 * The lifecycle-email unsubscribe headers are deliberately NOT applied:
 * this is an operational/finance notification to an operator-configured
 * address (BILLING_EXPORT_EMAIL), not marketing to an end user.
 */
class BillingExportEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{completed: int, refunded: int, partial_refund: int, chargeback: int, manual: int, revenue: float, failed_webhooks: int}  $summary
     * @param  array{filename: string, content: string, count: int}  $csv
     * @param  array{from: string, to: string}  $window
     */
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

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        // Only attach when there is something to attach — a zero-row week
        // with an empty CSV invites "is this file broken?" questions that
        // the summary already answers.
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

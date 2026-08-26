<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasMarketingUnsubscribe;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You haven't published in 7 days" nudge email. (Task H55)
 *
 * Iteration-007 (audit issue 10 / CAN-SPAM): Added RFC 8058 one-click
 * unsubscribe headers + visible footer unsubscribe link.
 *
 * Sent to users who registered >7 days ago but have 0 published
 * galleries. Encourages them to create + publish their first gallery.
 */
class InactiveUserNudge extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your 3D gallery is waiting — let\'s publish your first exhibition',
            // ITERATION-1 P0 FIX: RFC 8058 headers now come from the
            // HasMarketingUnsubscribe trait's headers() method — Envelope
            // has no `headers` constructor parameter (sending threw
            // "Unknown named parameter $headers" in the queue worker).
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inactive-nudge',
            text: 'emails.inactive-nudge-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Abandoned-cart recovery email. (Task H53)
 *
 * Sent to users who clicked "Upgrade" but didn't complete 2Checkout
 * checkout within 24 hours. Includes a resume-checkout link that
 * re-generates the 2Checkout buy URL with the same external-reference
 * token (valid for 7 days).
 */
class AbandonedCartEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public PendingUpgrade $pendingUpgrade,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->pendingUpgrade->plan);
        return new Envelope(
            subject: "Your Exospace {$planName} upgrade is waiting — pick up where you left off",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-cart',
            text: 'emails.abandoned-cart-text',
        );
    }
}

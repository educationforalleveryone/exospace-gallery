<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * M-9: Dunning email — sent when a recurring subscription payment fails.
 *
 * 3-email sequence:
 *   Step 1 (immediate): "Your payment failed — please update your card"
 *   Step 2 (day 3):     "Still failing — update your card to avoid losing access"
 *   Step 3 (day 7):     "Final notice — your subscription will be cancelled"
 *
 * The email subject + body escalate in urgency with each step. All 3
 * include a link to the 2Checkout customer portal where the user can
 * update their payment method.
 *
 * CAN-SPAM/GDPR compliance:
 *   - Dunning emails are TRANSACTIONAL (not marketing) — they're sent
 *     regardless of marketing_consent because they're required to fulfill
 *     the user's subscription contract.
 *   - Includes physical postal address (EMAIL-2/EMAIL-9, fixed in P0-3).
 *   - No unsubscribe link (transactional emails are exempt from CAN-SPAM
 *     unsubscribe requirements, but we include a "manage your billing" link).
 *
 * Implements ShouldQueue (P2-18 pattern) so the email send doesn't block
 * the webhook handler.
 */
class DunningEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $step,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->user->plan);

        return new Envelope(
            subject: match ($this->step) {
                1       => "Action needed: Your Exospace {$planName} payment failed",
                2       => "Reminder: Your {$planName} subscription payment is still failing",
                3       => "Final notice: Your {$planName} subscription will be cancelled",
                default => "Your Exospace subscription payment failed",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dunning',
            text: 'emails.dunning-text',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice sent to a user's OLD email address after the account's
 * email address is changed (Iteration 7).
 *
 * Why the OLD address: at the moment the change lands, the old address is
 * still the one the (possibly hijacked) account owner controls... or, in the
 * compromise scenario, the one the REAL owner controls. If the change was
 * legitimate, the owner sees a harmless confirmation and the new address
 * receives the verification link separately. If the change was NOT
 * legitimate (hijacked session), this notice is the real owner's only
 * signal — their inbox is the one channel the attacker did not capture.
 *
 * Deliberately contains NO action links (no "undo", no "revert"): there is
 * no self-serve revert flow, and link-bearing security notices train users
 * for phishing. The support address is rendered as plain text on purpose.
 *
 * Transactional security notice — never marketing, so no unsubscribe header
 * (no HasMarketingUnsubscribe trait, unlike the marketing mailables).
 * Implements ShouldQueue so the PATCH request does not block on the mailer.
 */
class EmailChangedNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public User $user, public string $oldEmail) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Exospace email address was changed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.email-changed',
            text: 'emails.email-changed-text',
            with: [
                'user' => $this->user,
                'oldEmail' => $this->oldEmail,
                'newEmail' => $this->user->email,
                'changedAt' => now()->format('F j, Y \a\t g:ia T'),
                'supportEmail' => 'support@exospace.gallery',
            ],
        );
    }
}

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
 * Security notice sent to the user's email address after their password
 * was changed from inside the account (Iteration 8).
 *
 * Why it exists: if the change was made by a hijacked session, the real
 * owner's inbox is the one channel the attacker did not capture. The
 * notice is the owner's signal to use the forgot-password flow and
 * reclaim the account. If the change was legitimate, it is a harmless
 * confirmation.
 *
 * Deliberately contains NO action links (no "reset here" button): the
 * notice teaches the reader to reach the sign-in page's own "Forgot
 * password" flow — link-bearing security notices train users for phishing
 * (same rationale as EmailChangedNoticeMail). The support address is
 * rendered as plain text on purpose.
 *
 * Transactional security notice — never marketing, so no unsubscribe header
 * (no HasMarketingUnsubscribe trait, unlike the marketing mailables).
 * Implements ShouldQueue so the PUT request does not block on the mailer.
 */
class PasswordChangedNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public User $user) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Exospace password was changed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.password-changed',
            text: 'emails.password-changed-text',
            with: [
                'user' => $this->user,
                'email' => $this->user->email,
                'changedAt' => now()->format('F j, Y \a\t g:ia T'),
                'supportEmail' => 'support@exospace.gallery',
            ],
        );
    }
}

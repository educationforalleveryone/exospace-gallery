<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The branded password-reset email.
 *
 * RESET-ITERATION FIX: previously the reset email was rendered by Laravel's
 * generic markdown template (subject "Reset Password Notification", vendor
 * styling) while every other Exospace email uses the branded table-based
 * layout. This mailable presents the SAME framework-generated reset URL with
 * the project's own email system (emails.partials.layout, html + text).
 *
 * Not ShouldQueue: the reset link must land while the user is still sitting
 * on the forgot-password page. Laravel's default reset notification also
 * sends synchronously — the timing semantics are deliberately unchanged.
 *
 * Deliberately NOT marketing email: no List-Unsubscribe headers, no
 * unsubscribe footer (transactional security email — CAN-SPAM exempt, and
 * the shared layout only renders an unsubscribe link when $unsubscribeUrl
 * is passed, which this mailable never does).
 */
class PasswordResetMail extends Mailable
{
    public function __construct(
        /** The account the reset link is for — recipient is always this user. */
        public User $user,
        /** The framework-generated absolute reset URL (contains the one-time token). */
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Exospace password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            text: 'emails.password-reset-text',
            with: [
                'expireMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}

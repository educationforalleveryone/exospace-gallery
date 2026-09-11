<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The branded email-verification email.
 *
 * VERIFICATION-ITERATION FIX: previously the verification email was rendered
 * by Laravel's generic markdown template (subject "Verify Email Address",
 * vendor styling) while every other Exospace email uses the branded
 * table-based layout. This mailable presents the SAME framework-generated
 * signed verification URL (built by App\Notifications\Auth\VerifyEmail from
 * the parent's verificationUrl()) with the project's own email system
 * (emails.partials.layout, html + text).
 *
 * Not ShouldQueue: the framework's SendEmailVerificationNotification listener
 * sends synchronously during registration, and the timing semantics are
 * deliberately unchanged — the link must land while the user is still sitting
 * on the "Check your inbox" screen.
 *
 * Deliberately NOT marketing email: no List-Unsubscribe headers, no
 * unsubscribe footer (transactional security email — CAN-SPAM exempt, and
 * the shared layout only renders an unsubscribe link when $unsubscribeUrl
 * is passed, which this mailable never does).
 */
class VerifyEmailMail extends Mailable
{
    public function __construct(
        /** The account the verification link is for — recipient is always this user. */
        public User $user,
        /** The framework-generated absolute signed verification URL. */
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your Exospace email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            text: 'emails.verify-email-text',
            with: [
                'expireMinutes' => (int) config('auth.verification.expire', 60),
            ],
        );
    }
}

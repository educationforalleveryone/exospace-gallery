<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;

/**
 * Exospace's email-verification notification.
 *
 * VERIFICATION-ITERATION FIX: extends the framework's VerifyEmail notification
 * so that ALL security mechanics stay 100% Laravel-owned — the signed URL
 * construction (temporarySignedRoute with id + sha1(email) hash, expiry from
 * config('auth.verification.expire')), the HMAC signature over the exact
 * parameter set, and the framework's EmailVerificationRequest that validates
 * it. Nothing about the link itself changes.
 *
 * Only the email PRESENTATION is customized:
 *
 *  - Branded subject ("Confirm your Exospace email address" instead of the
 *    generic "Verify Email Address").
 *  - Rendered through App\Mail\VerifyEmailMail so it uses the same
 *    table-based, inline-CSS, Outlook/Gmail-compatible layout as every other
 *    Exospace transactional email (emails.partials.layout) — html + text.
 *
 * The User model opts into this notification via sendEmailVerificationNotification().
 */
class VerifyEmail extends FrameworkVerifyEmail
{
    /**
     * Build the mail representation of the notification.
     *
     * Returns a Mailable (rather than a MailMessage with ->view()) because
     * the notification mail channel only honors the html view in that case —
     * a Mailable carries the html + text pair like every other Exospace email.
     */
    public function toMail($notifiable)
    {
        return (new VerifyEmailMail(
            $notifiable,
            $this->verificationUrl($notifiable),
        ))->to($notifiable->email);
    }
}

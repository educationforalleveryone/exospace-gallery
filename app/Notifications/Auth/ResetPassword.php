<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\PasswordResetMail;
use Illuminate\Auth\Notifications\ResetPassword as FrameworkResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Exospace's password-reset notification.
 *
 * RESET-ITERATION FIX: extends the framework's ResetPassword notification so
 * that ALL security mechanics stay 100% Laravel-owned — token generation,
 * hashed storage in password_reset_tokens, expiry (config/auth.php), throttle
 * and the resetUrl() construction (including the ?email= query parameter the
 * reset form pre-fills). Only the email PRESENTATION is customized:
 *
 *  - Branded subject ("Reset your Exospace password" instead of the generic
 *    "Reset Password Notification").
 *  - Rendered through App\Mail\PasswordResetMail so it uses the same
 *    table-based, inline-CSS, Outlook/Gmail-compatible layout as every other
 *    Exospace transactional email (emails.partials.layout) — html + text.
 *
 * The User model opts into this notification via sendPasswordResetNotification().
 */
class ResetPassword extends FrameworkResetPassword
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
        return (new PasswordResetMail(
            $notifiable,
            $this->resetUrl($notifiable),
        ))->to($notifiable->email);
    }
}

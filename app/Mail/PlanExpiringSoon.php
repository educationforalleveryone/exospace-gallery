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
 * "Your plan expires soon" reminder email. (Task H55)
 *
 * Sent to users whose admin-granted plan_expires_at is within 7 days.
 * Webhook-granted plans are lifetime (null plan_expires_at) and never
 * receive this email.
 */
class PlanExpiringSoon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->user->plan);
        $daysLeft = now()->diffInDays($this->user->plan_expires_at) ?? 0;
        return new Envelope(
            subject: "Your Exospace {$planName} plan expires in {$daysLeft} days",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-expiring',
            text: 'emails.plan-expiring-text',
        );
    }
}

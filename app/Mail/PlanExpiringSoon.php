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
 * "Your plan expires soon" reminder email. (Task H55)
 *
 * Iteration-007 (audit issue 10 / CAN-SPAM): Added RFC 8058 one-click
 * unsubscribe headers + visible footer unsubscribe link. Reminder
 * emails about expiry are a borderline transactional/marketing case;
 * including the unsubscribe link costs nothing and prevents Gmail
 * deferral.
 *
 * Sent to users whose admin-granted plan_expires_at is within 7 days.
 * Webhook-granted plans are lifetime (null plan_expires_at) and never
 * receive this email.
 */
class PlanExpiringSoon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->user->plan);
        $daysLeft = now()->diffInDays($this->user->plan_expires_at) ?? 0;
        return new Envelope(
            subject: "Your Exospace {$planName} plan expires in {$daysLeft} days",
            // ITERATION-1 P0 FIX: RFC 8058 headers now come from the
            // HasMarketingUnsubscribe trait's headers() method — Envelope
            // has no `headers` constructor parameter (sending threw
            // "Unknown named parameter $headers" in the queue worker).
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-expiring',
            text: 'emails.plan-expiring-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

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

class PlanExpiringSoon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->user->plan);
        $daysLeft = $this->daysLeft();

        return new Envelope(
            subject: $daysLeft === null
                ? "Your Exospace {$planName} plan expires soon"
                : "Your Exospace {$planName} plan expires in {$daysLeft} days",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-expiring',
            text: 'emails.plan-expiring-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
                'expiresOn'      => $this->user->plan_expires_at?->format('M j, Y'),
                'daysLeft'       => $this->daysLeft(),
            ],
        );
    }

    private function daysLeft(): ?int
    {
        $expiresAt = $this->user->plan_expires_at;

        if ($expiresAt === null) {
            return null;
        }

        // diffInDays() is fractional under Carbon 3 — users read whole days.
        return max(1, (int) ceil(now()->diffInDays($expiresAt)));
    }
}

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

class PlanUpgradedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(
        public User $user,
        public string $plan,
        public ?string $invoiceId = null,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->plan);
        $subject = $this->invoiceId
            ? "Your Exospace {$planName} plan is active (Invoice #{$this->invoiceId})"
            : "Your Exospace {$planName} plan is active";

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-upgraded',
            text: 'emails.plan-upgraded-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

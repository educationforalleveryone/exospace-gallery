<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasMarketingUnsubscribe;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbandonedCartEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(
        public User $user,
        public PendingUpgrade $pendingUpgrade,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->pendingUpgrade->plan);
        return new Envelope(
            subject: "Your Exospace {$planName} upgrade is waiting — pick up where you left off",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-cart',
            text: 'emails.abandoned-cart-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

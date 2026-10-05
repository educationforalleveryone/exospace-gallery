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
use Illuminate\Support\Facades\Log;

class AbandonedCartEmail extends Mailable implements ShouldQueue
{
    use HasMarketingUnsubscribe;
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public PendingUpgrade $pendingUpgrade,
    ) {}

    /**
     * The email is queued, so consent, bans and purchases can change before a
     * worker delivers it. Re-check against the fresh models at delivery.
     */
    public function send($mailer)
    {
        if (! $this->pendingUpgrade->isRecoverable()) {
            Log::info('AbandonedCart: dropped at delivery — no longer eligible', [
                'user_id' => $this->user->id,
                'pending_upgrade_id' => $this->pendingUpgrade->id,
            ]);

            return null;
        }

        return parent::send($mailer);
    }

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

<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasMarketingUnsubscribe;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You created your first gallery!" email. (Task H64)
 *
 * Iteration-007 (audit issue 10 / CAN-SPAM): Added RFC 8058 one-click
 * unsubscribe headers + visible footer unsubscribe link.
 *
 * Sent when a user creates their first gallery. Encourages them to
 * upload artwork and publish. Part of the activation email sequence:
 *   1. WelcomeEmail (on registration)
 *   2. FirstGalleryCreatedEmail (this — on first gallery creation)
 *   3. InactiveUserNudge (after 7 days of no published gallery)
 */
class FirstGalleryCreatedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use HasMarketingUnsubscribe;

    public function __construct(
        public User $user,
        public Gallery $gallery,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your gallery \"{$this->gallery->title}\" is ready — add your first artwork",
            headers: $this->unsubscribeHeaders($this->user),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.first-gallery',
            text: 'emails.first-gallery-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

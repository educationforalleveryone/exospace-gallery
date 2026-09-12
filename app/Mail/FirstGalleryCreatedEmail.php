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

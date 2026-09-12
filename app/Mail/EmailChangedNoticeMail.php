<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangedNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $oldEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Exospace email address was changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-changed',
            text: 'emails.email-changed-text',
            with: [
                'user' => $this->user,
                'oldEmail' => $this->oldEmail,
                'newEmail' => $this->user->email,
                'changedAt' => now()->format('F j, Y \a\t g:ia T'),
                'supportEmail' => 'support@exospace.gallery',
            ],
        );
    }
}

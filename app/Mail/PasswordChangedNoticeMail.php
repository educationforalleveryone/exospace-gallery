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

class PasswordChangedNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Exospace password was changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-changed',
            text: 'emails.password-changed-text',
            with: [
                'user' => $this->user,
                'email' => $this->user->email,
                'changedAt' => now()->format('F j, Y \a\t g:ia T'),
                'supportEmail' => 'support@exospace.gallery',
            ],
        );
    }
}

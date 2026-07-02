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
 * "You haven't published in 7 days" nudge email. (Task H55)
 *
 * Sent to users who registered >7 days ago but have 0 published
 * galleries. Encourages them to create + publish their first gallery.
 */
class InactiveUserNudge extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your 3D gallery is waiting — let\'s publish your first exhibition',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inactive-nudge',
            text: 'emails.inactive-nudge-text',
        );
    }
}

<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P2-18 FIX: Added ShouldQueue so the email is queued rather than
 * blocking the HTTP request on SMTP/Resend latency. TeamController::invite()
 * previously blocked the team owner's "Send invitation" POST on email send.
 */
class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->invitation->team->name} on Exospace",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation',
        );
    }
}

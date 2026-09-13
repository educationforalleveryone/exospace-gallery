<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly ?string $plaintextToken = null,
    ) {}

    public function invitationLink(): string
    {
        // Queue restoration re-fetches the invitation from the database, so the
        // runtime plaintext_token attribute is gone by render time.
        $token = $this->plaintextToken ?? $this->invitation->plaintext_token ?? $this->invitation->token;

        return \Illuminate\Support\Facades\URL::signedRoute('team-invitations.show', ['token' => $token]);
    }

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
            with: ['invitationLink' => $this->invitationLink()],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DunningEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $step,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->user->plan);

        return new Envelope(
            subject: match ($this->step) {
                1       => "Action needed: Your Exospace {$planName} payment failed",
                2       => "Reminder: Your {$planName} subscription payment is still failing",
                3       => "Final notice: Your {$planName} subscription will be cancelled",
                default => "Your Exospace subscription payment failed",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dunning',
            text: 'emails.dunning-text',
        );
    }
}

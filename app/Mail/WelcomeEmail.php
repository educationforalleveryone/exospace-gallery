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
 * Welcome email sent to new users after registration.
 *
 * (Task H03 / audit H4) — previously this mailable existed but no listener
 * ever sent it. The RegisteredUserController comment said "the welcome
 * email boot hook also skips invited users" but the hook didn't exist.
 * New users got a verification email and nothing else — for a freemium
 * SaaS where the free plan is the trial, the absence of a welcome email
 * directly hurt activation.
 *
 * Now wired via SendWelcomeEmail listener on the Illuminate\Auth\Events\Registered
 * event. See App\Providers\EventServiceProvider (or AppServiceProvider in
 * Laravel 11+ which auto-discovers listeners).
 */
class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Exospace — Let\'s Create Your First Gallery',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
        );
    }
}

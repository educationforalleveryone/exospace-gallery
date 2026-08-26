<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasMarketingUnsubscribe;
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
 * Iteration-007 (audit issue 10 / CAN-SPAM): Now emits RFC 8058
 * List-Unsubscribe + List-Unsubscribe-Post headers and passes
 * $unsubscribeUrl to the layout so the footer "Unsubscribe" link
 * renders. Gmail/Yahoo will display the one-click "Unsubscribe"
 * button in the inbox preview, satisfying the Feb-2024 bulk-sender
 * requirement.
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
    use HasMarketingUnsubscribe;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Exospace — Let\'s Create Your First Gallery',
            // ITERATION-1 P0 FIX: RFC 8058 headers now come from the
            // HasMarketingUnsubscribe trait's headers() method — Envelope
            // has no `headers` constructor parameter (sending threw
            // "Unknown named parameter $headers" in the queue worker).
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

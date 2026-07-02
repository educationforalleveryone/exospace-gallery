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
 * Email sent to a user when their plan is upgraded (via 2Checkout webhook
 * or admin action).
 *
 * (Task H03 / audit H5) — previously users got NO confirmation after a
 * successful upgrade. They had to log in and check their dashboard to
 * confirm the upgrade worked. For a $29–$99 one-time purchase, a
 * confirmation email is a basic customer expectation and reduces
 * support tickets ("did my payment go through?").
 *
 * Triggered by WebhookController after a successful ORDER_CREATED
 * processing, and by SystemController::updatePlan after an admin
 * grants a plan.
 */
class PlanUpgradedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plan,
        public ?string $invoiceId = null,
    ) {}

    public function envelope(): Envelope
    {
        $planName = ucfirst($this->plan);
        $subject = $this->invoiceId
            ? "Your Exospace {$planName} plan is active (Invoice #{$this->invoiceId})"
            : "Your Exospace {$planName} plan is active";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-upgraded',
            text: 'emails.plan-upgraded-text',
        );
    }
}

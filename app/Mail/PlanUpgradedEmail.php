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
 * Email sent to a user when their plan is upgraded (via 2Checkout webhook
 * or admin action).
 *
 * Iteration-007 (audit issue 10 / CAN-SPAM): Added RFC 8058 one-click
 * unsubscribe headers + visible footer unsubscribe link. Although this
 * email is primarily transactional (confirmation of a completed purchase),
 * it CAN be considered marketing if it includes upgrade CTAs. We err
 * on the side of including the unsubscribe link to satisfy Gmail/Yahoo's
 * bulk-sender requirements.
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
    use HasMarketingUnsubscribe;

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

        return new Envelope(
            subject: $subject,
            headers: $this->unsubscribeHeaders($this->user),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.plan-upgraded',
            text: 'emails.plan-upgraded-text',
            with: [
                'unsubscribeUrl' => $this->unsubscribeUrl($this->user),
            ],
        );
    }
}

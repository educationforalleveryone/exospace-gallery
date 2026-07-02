<?php

namespace App\Mail;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email alert sent to all super-admins when a destructive super-admin
 * action is performed. (Task H52 / audit H18)
 *
 * This is a security notification — it lets the super-admin team
 * monitor for compromised accounts or unauthorized actions.
 */
class SuperAdminActionAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AdminAuditLog $auditLog,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $actionLabels = [
            'user_deleted'      => 'User Deleted',
            'user_banned'       => 'User Banned',
            'super_admin_toggled' => 'Super-Admin Access Changed',
            'email_unverified'  => 'Email Verification Revoked',
            'plan_changed'      => 'User Plan Changed',
        ];

        $label = $actionLabels[$this->auditLog->action] ?? $this->auditLog->action;

        return new Envelope(
            subject: "[Security Alert] {$label} — Exospace Super-Admin",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.super-admin-alert',
            text: 'emails.super-admin-alert-text',
        );
    }
}

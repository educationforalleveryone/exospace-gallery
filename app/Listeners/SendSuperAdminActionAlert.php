<?php

namespace App\Listeners;

use App\Events\AdminAuditLogged;
use App\Mail\SuperAdminActionAlert;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send an email alert to all super-admins when a destructive super-admin
 * action is performed. (Task H52 / audit H18)
 *
 * This is a lightweight MFA alternative — instead of requiring a TOTP
 * code (which requires provisioning a secrets table + QR code flow),
 * we alert all super-admins whenever a destructive action fires. If
 * a super-admin's session is compromised, the other super-admins see
 * the alert and can investigate.
 *
 * Destructive actions that trigger this alert:
 *   - deleteUser (user_deleted)
 *   - banUser (user_banned)
 *   - toggleSuperAdmin (super_admin_toggled)
 *   - unverifyEmail (email_unverified)
 *   - updatePlan (plan_changed)
 *
 * For full MFA (TOTP), install pragmarx/google2fa-qrcode and add:
 *   - A `google2fa_secret` column to the users table
 *   - A setup flow at /profile/mfa
 *   - A middleware that requires a valid TOTP for /master-control/*
 * This is noted as future work — the email-alert approach is the
 * pragmatic first step.
 */
class SendSuperAdminActionAlert implements ShouldQueue
{
    public function handle(AdminAuditLogged $event): void
    {
        $auditLog = $event->auditLog;

        // Only alert on destructive actions
        $destructiveActions = [
            'user_deleted',
            'user_banned',
            'super_admin_toggled',
            'email_unverified',
            'plan_changed',
        ];

        if (! in_array($auditLog->action, $destructiveActions, true)) {
            return;
        }

        // Get all super-admins to notify (excluding the actor + banned/unverified)
        $superAdmins = User::where('is_super_admin', true)
            ->whereNotNull('email_verified_at')
            ->whereNull('banned_at')
            ->where('id', '!=', $auditLog->actor_id)
            ->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        foreach ($superAdmins as $admin) {
            try {
                Mail::to($admin->email)->send(new SuperAdminActionAlert($auditLog, $admin));
            } catch (\Throwable $e) {
                Log::warning('SendSuperAdminActionAlert: email send failed', [
                    'admin_id' => $admin->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}

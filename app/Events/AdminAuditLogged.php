<?php

namespace App\Events;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an admin audit log entry is created. (Task H52)
 *
 * The SendSuperAdminActionAlert listener catches this event and sends
 * an email alert to all super-admins when the action is destructive
 * (user_deleted, user_banned, super_admin_toggled, etc.).
 */
class AdminAuditLogged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AdminAuditLog $auditLog
    ) {}
}

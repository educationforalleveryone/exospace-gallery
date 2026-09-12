<?php

namespace App\Events;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminAuditLogged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AdminAuditLog $auditLog
    ) {}
}

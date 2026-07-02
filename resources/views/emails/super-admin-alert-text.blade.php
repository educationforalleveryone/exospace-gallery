EXOSPACE — Super-Admin Security Alert

Hi {{ $recipient->name }},

A destructive super-admin action was just performed on Exospace. You're receiving this email because you're a super-admin.

ACTION: {{ $auditLog->action }}
PERFORMED BY: Super-Admin #{{ $auditLog->actor_id }}
TARGET: {{ $auditLog->target_type }} #{{ $auditLog->target_id }}
TIMESTAMP: {{ $auditLog->created_at }}

@if($auditLog->payload)
DETAILS:
{{ json_encode($auditLog->payload, JSON_PRETTY_PRINT) }}
@endif

If you performed this action, no further action is needed. If you did NOT expect this alert, please investigate immediately — a super-admin account may be compromised.

Review the admin log: {{ config('app.url') }}/master-control

---

© {{ date('Y') }} Exospace Gallery. This is an automated security notification.

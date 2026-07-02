<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; padding: 20px; margin: 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        h2 { color: #1f2937; margin-bottom: 20px; }
        p { color: #4b5563; line-height: 1.6; margin-bottom: 15px; }
        .alert { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; padding: 16px; margin: 20px 0; }
        .alert strong { color: #92400e; }
        .details { background: #f9fafb; padding: 16px; border-radius: 6px; margin: 20px 0; font-family: monospace; font-size: 13px; color: #4b5563; }
        .btn { background: #7c3aed; color: white !important; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600; margin: 20px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>⚠️ Super-Admin Security Alert</h2>

        <p>Hi {{ $recipient->name }},</p>

        <p>A destructive super-admin action was just performed on Exospace. You're receiving this email because you're a super-admin.</p>

        <div class="alert">
            <strong>Action:</strong> {{ $auditLog->action }}<br>
            <strong>Performed by:</strong> Super-Admin #{{ $auditLog->actor_id }}<br>
            <strong>Target:</strong> {{ $auditLog->target_type }} #{{ $auditLog->target_id }}<br>
            <strong>Timestamp:</strong> {{ $auditLog->created_at }}
        </div>

        @if($auditLog->payload)
        <div class="details">
            <strong>Details:</strong><br>
            @json($auditLog->payload)
        </div>
        @endif

        <p>If you performed this action, no further action is needed. If you did NOT expect this alert, please investigate immediately — a super-admin account may be compromised.</p>

        <a href="{{ config('app.url') }}/master-control" class="btn">Review Admin Log</a>

        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. This is an automated security notification.
        </div>
    </div>
</body>
</html>

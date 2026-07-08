<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #111827; color: #e5e7eb; margin: 0; padding: 40px 20px; }
        .container { max-width: 560px; margin: 0 auto; background: #1f2937; border: 1px solid #374151; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #7c3aed, #4f46e5); padding: 32px; text-align: center; }
        .header h1 { color: white; margin: 0; font-size: 24px; }
        .body { padding: 32px; }
        .body p { color: #d1d5db; line-height: 1.6; margin: 0 0 16px; }
        .role-badge { display: inline-block; background: #374151; color: #a78bfa; border: 1px solid #6d28d9; padding: 4px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; margin: 0 4px; }
        .btn { display: inline-block; background: linear-gradient(135deg, #7c3aed, #4f46e5); color: white !important; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; margin: 8px 4px; }
        .btn-outline { background: transparent; border: 1px solid #374151; color: #9ca3af !important; }
        .actions { text-align: center; margin: 24px 0; }
        .footer { padding: 20px 32px; border-top: 1px solid #374151; text-align: center; }
        .footer p { color: #6b7280; font-size: 13px; margin: 4px 0; }
        .expires { background: #1a1f2e; border: 1px solid #2d3748; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 13px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Exospace</h1>
    </div>
    <div class="body">
        <p>Hi there,</p>
        <p>
            <strong style="color: #f3f4f6;">{{ $invitation->team->owner->name }}</strong>
            has invited you to join the team
            <strong style="color: #f3f4f6;">{{ $invitation->team->name }}</strong>
            on Exospace as
            <span class="role-badge">{{ ucfirst($invitation->role) }}</span>.
        </p>

        @if($invitation->team->description)
        <p style="color: #9ca3af; font-style: italic;">"{{ $invitation->team->description }}"</p>
        @endif

        <p>As an <strong style="color: #f3f4f6;">{{ $invitation->role }}</strong>, you'll be able to
            @if($invitation->role === 'editor')
                create and manage galleries within this team.
            @else
                view all galleries in this team.
            @endif
        </p>

        <div class="actions">
            <a href="{{ Illuminate\Support\Facades\URL::signedRoute('team-invitations.show', ['token' => $invitation->plaintext_token ?? $invitation->token]) }}" class="btn">
                View &amp; Respond to Invitation
            </a>
        </div>

        <div class="expires">
            ⏳ This invitation expires on {{ $invitation->expires_at->format('F j, Y \a\t g:i A') }}.
        </div>

        <p style="font-size: 13px; color: #6b7280;">
            If you don't have an Exospace account yet, you'll be prompted to create one after clicking Accept.
            Make sure to register with this email address: <strong>{{ $invitation->email }}</strong>
        </p>
    </div>
    <div class="footer">
        <p>You received this because {{ $invitation->email }} was invited to join a team.</p>
        <p>If you weren't expecting this, you can safely ignore this email.</p>
    </div>
</div>
</body>
</html>
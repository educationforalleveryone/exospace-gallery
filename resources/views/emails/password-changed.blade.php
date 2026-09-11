{{-- ITERATION-8: Security notice sent after a user's password was changed
    from inside their account. Informational only — no action links
    (see PasswordChangedNoticeMail docblock for the phishing-resistance
    rationale). --}}
@extends('emails.partials.layout')

@section('title', 'Your Exospace password was changed')

@section('preheader')
    {{-- Hidden inbox-preview text. Max 85 chars. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
        The password for your Exospace account was changed. Not you? Read this.
    </div>
@endsection

@section('content')
    <h2 class="email-text" style="color:#1f2937;font-size:22px;margin:0 0 20px 0;">Your password was changed</h2>

    <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 15px 0;">
        Hi {{ $user->name }},
    </p>

    <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 15px 0;">
        The password for your Exospace account ({{ $email }}) was changed on {{ $changedAt }}.
    </p>

    {{-- Change summary box --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:6px;margin:25px 0;">
        <tr>
            <td style="padding:20px;">
                <p class="email-text" style="color:#4b5563;margin:0 0 8px 0;">Account:</p>
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0 0 15px 0;">{{ $email }}</p>
                <p class="email-text" style="color:#4b5563;margin:0 0 8px 0;">Changed:</p>
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0;">{{ $changedAt }}</p>
            </td>
        </tr>
    </table>

    {{-- Was this you? --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:25px 0;">
        <tr>
            <td style="padding:20px;">
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0 0 10px 0;">Was this you?</p>
                <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 10px 0;">
                    If you made this change, no action is needed — use your new password the next time you sign in.
                    Your other signed-in sessions stay signed in, but devices using the "remember me" option
                    will need to sign in again.
                </p>
                <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0;">
                    If you did <strong>not</strong> make this change, someone may have gained access to
                    your account. Go to the Exospace sign-in page and use <strong>Forgot password</strong>
                    to set a new password immediately — then contact us:
                    <strong>{{ $supportEmail }}</strong> — from this email address, if you can.
                </p>
            </td>
        </tr>
    </table>

    <p class="email-muted" style="font-size:13px;color:#9ca3af;margin:0 0 20px 0;">
        For your safety this message contains no links. Always reach Exospace by typing our address
        into your browser yourself.
    </p>
@endsection

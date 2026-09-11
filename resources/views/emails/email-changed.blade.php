{{-- ITERATION-7: Security notice sent to a user's OLD email address after the
    account's email address was changed. Informational only — no action links
    (see EmailChangedNoticeMail docblock for the phishing-resistance rationale). --}}
@extends('emails.partials.layout')

@section('title', 'Your Exospace email address was changed')

@section('preheader')
    {{-- Hidden inbox-preview text. Max 85 chars. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
        Your Exospace account email was changed to {{ $newEmail }}. Not you? Read this.
    </div>
@endsection

@section('content')
    <h2 class="email-text" style="color:#1f2937;font-size:22px;margin:0 0 20px 0;">Your email address was changed</h2>

    <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 15px 0;">
        Hi {{ $user->name }},
    </p>

    <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 15px 0;">
        The email address on your Exospace account was changed on {{ $changedAt }}.
    </p>

    {{-- Change summary box --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:6px;margin:25px 0;">
        <tr>
            <td style="padding:20px;">
                <p class="email-text" style="color:#4b5563;margin:0 0 8px 0;">Previous address:</p>
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0 0 15px 0;">{{ $oldEmail }}</p>
                <p class="email-text" style="color:#4b5563;margin:0 0 8px 0;">New address:</p>
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0;">{{ $newEmail }}</p>
            </td>
        </tr>
    </table>

    {{-- Was this you? --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;margin:25px 0;">
        <tr>
            <td style="padding:20px;">
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0 0 10px 0;">Was this you?</p>
                <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 10px 0;">
                    If you made this change, no action is needed. We sent a confirmation link to your
                    new address ({{ $newEmail }}) — sign in with it after clicking the link.
                </p>
                <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0;">
                    If you did <strong>not</strong> make this change, someone may have gained access to
                    your account. Contact us as soon as possible:
                    <strong>{{ $supportEmail }}</strong> — from this email address, if you can.
                </p>
            </td>
        </tr>
    </table>

    <p class="email-muted" style="font-size:13px;color:#9ca3af;margin:0 0 20px 0;">
        Your account stays under your current password. Changing the email address alone does not
        change your password — but if you suspect someone else has access, change your password
        after regaining control.
    </p>
@endsection

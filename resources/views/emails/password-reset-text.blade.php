{{-- RESET-ITERATION FIX: plain-text alternative for the branded password-reset
    email. Ships alongside emails/password-reset.blade.php as the text part of
    App\Mail\PasswordResetMail (multipart/alternative for deliverability). --}}
Hi {{ $user->name }},

We received a request to reset the password for your Exospace account ({{ $user->email }}).

Open the link below to choose a new password. It works once and expires in {{ $expireMinutes }} minutes:

{{ $resetUrl }}

Didn't request this? You can safely ignore this email — your password stays unchanged until you follow the link. If you keep receiving these emails, please contact support.

— Exospace Gallery
{{ config('app.url') }}

{{-- VERIFICATION-ITERATION FIX: plain-text alternative for the branded
    email-verification email. Ships alongside emails/verify-email.blade.php
    as the text part of App\Mail\VerifyEmailMail (multipart/alternative for
    deliverability). --}}
Hi {{ $user->name }},

Welcome to Exospace! Please confirm that {{ $user->email }} is your email address to activate your account.

Open the link below to verify your email. It expires in {{ $expireMinutes }} minutes:

{!! $verificationUrl !!}

Didn't create an account? You can safely ignore this email — no account will be activated. If you keep receiving these emails, please contact support.

— Exospace Gallery
{{ config('app.url') }}

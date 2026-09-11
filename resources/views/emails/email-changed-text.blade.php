EXOSPACE

Your Exospace email address was changed

Hi {{ $user->name }},

The email address on your Exospace account was changed on {{ $changedAt }}.

Previous address: {{ $oldEmail }}
New address: {{ $newEmail }}

WAS THIS YOU?

If you made this change, no action is needed. We sent a confirmation link to
your new address ({{ $newEmail }}) — sign in with it after clicking the link.

If you did NOT make this change, someone may have gained access to your
account. Contact us as soon as possible: {{ $supportEmail }} — from this email
address, if you can.

Note: your account stays under your current password. Changing the email
address alone does not change your password — but if you suspect someone else
has access, change your password after regaining control.

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Building the future of digital art exhibitions.
{{ config('app.url') }}

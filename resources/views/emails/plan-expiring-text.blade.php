EXOSPACE

Your {{ ucfirst($user->plan) }} plan expires soon

Hi {{ $user->name }},

Your {{ ucfirst($user->plan) }} plan expires on {{ $user->plan_expires_at->format('M j, Y') }}.
That's in {{ now()->diffInDays($user->plan_expires_at) }} days.

After your plan expires, your account will be downgraded to the Free plan (1 gallery, 10 images). Your galleries and images will remain — they just won't be publicly accessible beyond the Free plan limits.

To keep all your {{ ucfirst($user->plan) }} features, renew your plan:

{{ config('app.url') }}/billing

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

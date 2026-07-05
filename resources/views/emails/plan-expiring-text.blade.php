EXOSPACE

Your {{ ucfirst($user->plan) }} plan expires soon

Hi {{ $user->name }},

Your {{ ucfirst($user->plan) }} plan expires on {{ $user->plan_expires_at->format('M j, Y') }}.
That's in {{ now()->diffInDays($user->plan_expires_at) }} days.

After your plan expires, your account will be downgraded to the Free plan (1 gallery, 10 images). Your existing galleries will remain in your account, but only your first gallery will be publicly accessible. Custom domains, logos, and audio will be removed from your galleries.

To keep all your {{ ucfirst($user->plan) }} features, renew your plan:

{{ config('app.url') }}/billing/upgrade/{{ $user->plan }}

Or view all billing options:
{{ config('app.url') }}/billing

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
@if(config('app.business_address'))
{{ config('app.business_address') }}
@endif
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

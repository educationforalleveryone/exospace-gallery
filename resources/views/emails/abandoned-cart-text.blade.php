EXOSPACE

You're almost there, {{ $user->name }}!

You started upgrading to {{ ucfirst($pendingUpgrade->plan) }} but didn't finish checkout. No worries — your upgrade is still waiting.

{{ ucfirst($pendingUpgrade->plan) }} Plan — {{ $pendingUpgrade->plan === 'studio' ? '$99 one-time' : '$29 one-time' }}
Lifetime access — no subscription

@if($pendingUpgrade->plan === 'pro')
What you'll unlock:
- 5 galleries · 100 images total
- 7 venues including Industrial Loft & Dark Museum
- Background music & exhibition scheduling
- No Exospace watermark
@elseif($pendingUpgrade->plan === 'studio')
What you'll unlock:
- Unlimited galleries · 500 images each
- All 11 venues including custom domain support
- White-label branding & custom curtain logo
- Advanced analytics · team collaboration
@endif

COMPLETE YOUR UPGRADE:

{{ config('app.url') }}/billing/upgrade/{{ $pendingUpgrade->plan }}

This link will resume your checkout with 2Checkout. Your upgrade activates automatically after payment.

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

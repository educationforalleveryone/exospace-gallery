EXOSPACE

Your {{ ucfirst($plan) }} plan is active!

Hi {{ $user->name }},

Your Exospace {{ ucfirst($plan) }} plan is now active. Thank you for your purchase!

@if($invoiceId)
RECEIPT
-------
Plan: {{ ucfirst($plan) }}
Invoice ID: {{ $invoiceId }}
Status: Completed
{{-- ITERATION-5 (billing truth): sent for both one-time and subscription
     purchases — do not hardcode a billing type here. --}}
Type: {{ ucfirst($plan) }} plan purchase

Keep this invoice ID for your records. You can also view it anytime in your billing portal: {{ config('app.url') }}/billing
@endif

WHAT'S UNLOCKED WITH {{ strtoupper($plan) }}

@if($plan === 'pro')
- 5 galleries · 100 images total
- All 7 standard venues
- Background music & exhibition scheduling
- No Exospace watermark
@elseif($plan === 'studio')
- Unlimited galleries · 500 images each
- All 11 venues including Penthouse, Cyber Gallery, Sculpture Garden
- Custom domain (yourname.com) — set up in your gallery settings
- White-label branding & custom curtain logo
@endif

CREATE A NEW GALLERY

{{ config('app.url') }}/admin/galleries

QUESTIONS?

Reply to this email or visit our Help Center: {{ config('app.url') }}/contact

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

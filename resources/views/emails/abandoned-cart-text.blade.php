EXOSPACE

You're almost there, {{ $user->name }}!

You started upgrading to {{ ucfirst($pendingUpgrade->plan) }} but didn't finish checkout. No worries — your upgrade is still waiting.

@php
    // ITERATION-5 (billing truth): match the checkout type the user
    // actually started — recurring vs one-time (see abandoned-cart.blade.php).
    $recurringProductId = config('services.2checkout.recurring_product_id_' . $pendingUpgrade->plan);
    $isRecurringCheckout = $recurringProductId && (string) $pendingUpgrade->product_id === (string) $recurringProductId;

    // FIX (2026-08-31): the price line used inline @if/@else/@endif directives
    // GLUED to word characters (e.g. "/month@else{{ ... }}@endif" and
    // "anytime@else ... purchase@endif"). Blade's \B@ regex does not parse a
    // directive that is attached directly to a word character, so the @else /
    // @endif on these two lines were rendered as literal text. The @if still
    // compiled, the closing @endif did not — the template compiled to PHP with
    // an unclosed if(): and crashed with "syntax error, unexpected end of file,
    // expecting elseif or else or endif" whenever the abandoned-cart email was
    // rendered. Computing the strings here keeps the output identical while
    // removing the fragile inline directives entirely.
    $priceLine = $isRecurringCheckout
        ? '$' . config('services.2checkout.recurring_price_' . $pendingUpgrade->plan . '_monthly', $pendingUpgrade->plan === 'studio' ? '14.99' : '4.99') . '/month'
        : ($pendingUpgrade->plan === 'studio' ? '$99 one-time' : '$29 one-time');
    $billingNote = $isRecurringCheckout
        ? 'Monthly subscription — cancel anytime'
        : 'Lifetime access — one-time purchase';
@endphp
{{ ucfirst($pendingUpgrade->plan) }} Plan — {{ $priceLine }}
{{ $billingNote }}

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

{{ config('app.url') }}/billing/upgrade/{{ $pendingUpgrade->plan }}{{ $isRecurringCheckout ? '?recurring=1' : '' }}

Click the link above to start a new checkout with 2Checkout. Your upgrade activates automatically after payment.

---

You're receiving this email because you started an upgrade on Exospace.
Unsubscribe: {{ \Illuminate\Support\Facades\URL::signedRoute('unsubscribe.show', ['user' => $user->id]) }}

© {{ date('Y') }} Exospace Gallery. All rights reserved.
@if(config('app.business_address'))
{{ config('app.business_address') }}
@endif
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

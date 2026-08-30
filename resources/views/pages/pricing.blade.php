{{-- P1-14 FIX (audit): Converted from standalone HTML to @extends('layouts.public').
    Previously this page had its own <html>, <head>, <nav>, no SEO meta, no
    shared footer, no cookie banner, no toast system, no PWA service worker.
    Now it inherits all of those from the public layout while keeping the
    custom pricing card CSS inline in the content section. --}}
@extends('layouts.public')

@section('title', 'Pricing — Exospace 3D Gallery')
@section('description', 'Create museum-quality 3D art exhibitions. Free to start. Pro $29 one-time or $4.99/mo. Studio $99 one-time or $14.99/mo — custom domains and white-label branding.')

@php
    // CONV-3: Determine the current user's plan for "already on this plan" state
    $currentPlan = auth()->check() ? auth()->user()->plan : null;

    // ITERATION-5 (billing truth): monthly subscription options are a real
    // product (M-1: cancel/reactivate routes, dunning emails, webhook
    // recurring lifecycle). Surface the monthly alternative from the SAME
    // config the billing portal uses — and only when the recurring 2Checkout
    // products are actually configured. Until iteration 5 this page claimed
    // "No subscription. No recurring charges.", which contradicted the
    // welcome page and the billing portal.
    $recurringProPrice    = config('services.2checkout.recurring_price_pro_monthly', '4.99');
    $recurringStudioPrice = config('services.2checkout.recurring_price_studio_monthly', '14.99');
    $hasRecurringPro      = config('services.2checkout.recurring_product_id_pro');
    $hasRecurringStudio   = config('services.2checkout.recurring_product_id_studio');
@endphp

@section('content')
<style>
    /* ── Pricing page custom styles ─────────────────────── */
    .pricing-hero {
        text-align: center; padding: 5rem 2rem 3rem;
        max-width: 800px; margin: 0 auto;
    }
    .delivery-badge {
        display: inline-flex; align-items: center; gap: 0.5rem;
        padding: 0.4rem 0.9rem;
        background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.3);
        border-radius: 999px;
        font-size: 0.75rem; color: #c4b5fd; font-weight: 500;
        margin-bottom: 1.5rem;
    }
    .pricing-hero h1 {
        font-size: clamp(2.2rem, 5vw, 3.5rem); font-weight: 800; line-height: 1.1;
        margin-bottom: 1rem;
        color: #f3f4f6;
    }
    .grad {
        background: linear-gradient(135deg, #a78bfa, #8b5cf6);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .pricing-hero p {
        font-size: 1.05rem; color: #9ca3af; line-height: 1.6;
        max-width: 600px; margin: 0 auto;
    }

    .pricing-cards {
        display: grid; gap: 1.5rem;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        max-width: 1200px; margin: 3rem auto; padding: 0 2rem;
    }
    .pricing-card {
        background: #0f1117; /* ink-900 */
        border: 1px solid rgba(139, 92, 246, 0.12);
        border-radius: 18px; padding: 2rem;
        display: flex; flex-direction: column;
        transition: all 0.3s ease;
    }
    .pricing-card:hover { border-color: rgba(139, 92, 246, 0.3); transform: translateY(-4px); }
    .pricing-card.featured {
        border-color: rgba(139, 92, 246, 0.4);
        position: relative;
    }
    .pricing-card.featured::before {
        content: 'MOST POPULAR';
        position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
        background: linear-gradient(135deg, #a78bfa, #8b5cf6);
        color: white; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em;
        padding: 4px 12px; border-radius: 999px;
    }
    .card-tier {
        font-size: 0.85rem; font-weight: 600; color: #9ca3af;
        text-transform: uppercase; letter-spacing: 0.1em;
        margin-bottom: 0.5rem;
    }
    .card-price {
        display: flex; align-items: baseline; gap: 0.25rem;
        margin-bottom: 1rem;
    }
    .dollar { font-size: 1.5rem; color: #9ca3af; font-weight: 600; }
    .amount { font-size: 3rem; font-weight: 800; color: #f3f4f6; }
    .period { font-size: 0.85rem; color: #6b7280; }
    .card-desc { font-size: 0.9rem; color: #9ca3af; line-height: 1.6; margin-bottom: 1.5rem; }
    .features { list-style: none; flex-grow: 1; margin-bottom: 1.5rem; }
    .features li {
        display: flex; align-items: flex-start; gap: 0.65rem;
        font-size: 0.85rem; color: #d1d5db; line-height: 1.5;
        padding: 0.4rem 0;
    }
    .features li.dim { color: #4b5563; }
    .features li strong { color: #f3f4f6; font-weight: 600; }
    .icon { flex-shrink: 0; width: 16px; height: 16px; margin-top: 2px; }
    .feat-group { display: flex; flex-direction: column; gap: 1px; }
    .feat-label { font-weight: 600; color: #f3f4f6; }
    .feat-detail { font-size: 0.75rem; color: #6b7280; }

    /* ITERATION-4: page-local .btn/.btn-primary/.btn-outline deleted — they
       shadowed the design-system kit so .btn-primary never actually rendered
       here. CTAs below use the shared kit (btn-primary / btn-secondary). */
    .trust-footer {
        text-align: center; padding: 2.5rem 2rem;
        border-top: 1px solid rgba(139, 92, 246, 0.08);
        margin-top: 3rem;
    }
    .lock-row {
        display: inline-flex; align-items: center; gap: 0.5rem;
        color: #8b5cf6; margin-bottom: 0.5rem;
    }
    .trust-footer p {
        font-size: 0.8rem; color: #6b7280; line-height: 1.7;
    }

    .faq {
        max-width: 720px; margin: 4rem auto; padding: 0 2rem;
    }
    .faq h2 {
        font-size: 1.6rem; font-weight: 700; text-align: center; margin-bottom: 2rem;
        color: #f3f4f6;
    }
    .faq-item {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(139, 92, 246, 0.1);
        border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 0.6rem;
    }
    .faq-item summary {
        font-size: 0.9rem; font-weight: 600; color: #f3f4f6;
        cursor: pointer; list-style: none;
        display: flex; justify-content: space-between; align-items: center;
    }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary .plus { color: #6b7280; font-size: 1.2rem; transition: transform 0.2s; }
    .faq-item[open] summary .plus { transform: rotate(45deg); }
    .faq-item p {
        font-size: 0.82rem; color: #9ca3af; line-height: 1.7; padding-top: 0.6rem;
    }

    /* ITERATION-5: monthly alternative under the card price (rendered only
       when the recurring product is configured). */
    .card-cycle {
        font-size: 0.78rem; color: #9ca3af; text-align: center;
        margin: -0.6rem 0 0.9rem;
    }

    @media (max-width: 720px) {
        .pricing-hero { padding: 3rem 1.5rem 2rem; }
        .pricing-cards { padding: 0 1rem; }
    }
</style>

<!-- Hero -->
<section class="pricing-hero">
    <div class="delivery-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Instant digital delivery via browser
    </div>
    <h1>Two venues free.<br><span class="grad">Unlock them all.</span></h1>
    <p>Each plan unlocks more virtual venues — 11 distinct 3D spaces with their own architecture, scale, and atmosphere.</p>
    {{-- CONV-1: Social proof badge --}}
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:1.5rem;">
        <div style="display:flex;">
            @php
                // Show 5 star icons
                for ($i = 0; $i < 5; $i++) {
                    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
                }
            @endphp
        </div>
        <span style="font-size:0.8rem;color:#9ca3af;">Loved by artists worldwide</span>
    </div>
</section>

<!-- Cards -->
<div class="pricing-cards">

    <!-- FREE -->
    <div class="pricing-card">
        <div class="card-tier">Free</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">0</span>
            <span class="period">forever</span>
        </div>
        <p class="card-desc">Try Exospace with one gallery. Perfect for portfolios and personal projects.</p>
        <ul class="features">
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                1 gallery · up to 10 images
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <span class="feat-group">
                    <span class="feat-label">2 venues</span>
                    <span class="feat-detail">Modern White Cube · Infinite Void</span>
                </span>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Guided tour · analytics · PIN protection
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Shareable public link
            </li>
            <li class="dim">
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#4b5563" stroke-width="2.5"><line x1="3" y1="3" x2="9" y2="9"/><line x1="9" y1="3" x2="3" y2="9"/></svg></span>
                "Created with Exospace" watermark
            </li>
        </ul>
        <a href="{{ $currentPlan ? route('admin.dashboard') : route('register') }}" class="btn btn-secondary w-full">
            {{ $currentPlan === 'free' ? 'Your Current Plan ✓' : 'Get Started Free' }}
        </a>
    </div>

    <!-- PRO (featured) -->
    <div class="pricing-card featured">
        <div class="card-tier">Pro</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">29</span>
            <span class="period">/ one-time</span>
        </div>
        @if($hasRecurringPro)
        <p class="card-cycle">or ${{ $recurringProPrice }}/mo — cancel anytime</p>
        @endif
        <p class="card-desc">For serious creators. More venues, more images, no watermark.</p>
        <ul class="features">
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>5 galleries</strong> · 100 images total
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <span class="feat-group">
                    <span class="feat-label">7 venues</span>
                    <span class="feat-detail">White Cube · Infinite Void · Industrial Loft · Dark Museum · Zen Gallery · Crystal Cathedral · Nebula Drift</span>
                </span>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Background music · exhibition scheduling
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Watermark removed</strong>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Priority email support
            </li>
        </ul>
        @if($currentPlan === 'pro')
        <button type="button" class="btn btn-primary w-full" disabled>Your Current Plan ✓</button>
        @elseif($currentPlan === 'studio')
        <button type="button" class="btn btn-primary w-full" disabled>Included in Studio ✓</button>
        @else
        <button type="button" class="btn btn-primary w-full" data-click="openModalAnchor" data-arg="upgrade-modal-pro">Upgrade to Pro — $29</button>
        {{-- ITERATION-2 (trial wiring): surface the 14-day trial backend
             (2CO-8 — rate-limited, one per user, no card) to eligible
             logged-in Free users. Guests get the register deep-link. --}}
        @auth
            @if(auth()->user()->plan === 'free' && ! auth()->user()->hasUsedTrial())
            <form action="{{ route('billing.start-trial', 'pro') }}" method="POST" style="margin-top:0.6rem;">
                @csrf
                <button type="submit" class="btn btn-secondary w-full">
                    or try Pro free for 14 days →
                </button>
            </form>
            @endif
        @else
            <p style="font-size:0.75rem; color:#6b7280; margin-top:0.6rem; text-align:center;">New here? <a href="{{ route('register') }}" style="color:#8b5cf6; text-decoration:underline;">Create a free account</a> to start a 14-day Pro trial.</p>
        @endauth
        @endif
    </div>

    <!-- STUDIO -->
    <div class="pricing-card">
        <div class="card-tier">Studio</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">99</span>
            <span class="period">/ one-time</span>
        </div>
        @if($hasRecurringStudio)
        <p class="card-cycle">or ${{ $recurringStudioPrice }}/mo — cancel anytime</p>
        @endif
        <p class="card-desc">For agencies and professionals. Every venue, custom domains, white-label branding.</p>
        <ul class="features">
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Unlimited galleries · 500 images total</strong>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <span class="feat-group">
                    <span class="feat-label">All 11 venues</span>
                    <span class="feat-detail">Penthouse · Cyber Gallery · Sculpture Garden · Mirror Lake + every Pro venue</span>
                </span>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <span class="feat-group">
                    <span class="feat-label">Custom domain</span>
                    <span class="feat-detail">Host galleries on your own subdomain (e.g. gallery.yourname.com)</span>
                </span>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <span class="feat-group">
                    <span class="feat-label">White-label branding</span>
                    <span class="feat-detail">Your logo on every gallery, no Exospace watermark</span>
                </span>
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Background music · scheduling · team collaboration
            </li>
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Dedicated account manager</strong>
            </li>
        </ul>
        @if($currentPlan === 'studio')
        <button type="button" class="btn btn-secondary w-full" disabled>Your Current Plan ✓</button>
        @else
        <button type="button" class="btn btn-secondary w-full" data-click="openModalAnchor" data-arg="upgrade-modal-studio">Upgrade to Studio — $99</button>
        @endif
    </div>
</div>

<!-- Trust footer -->
<div class="trust-footer">
    <div class="lock-row">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span style="font-size:0.78rem; color:#4b5563; font-weight:500;">Secure Payment</span>
    </div>
    <p>
        Transactions processed securely via <strong>2Checkout</strong>.<br>
        Instant digital access upon payment. Pay once for lifetime access, or choose a
        monthly subscription — cancel anytime from your billing page.
    </p>
</div>

{{-- CONV-2: Feature comparison table. Lets visitors scan all features at a
     glance without re-reading each card. Mobile-friendly: table scrolls
     horizontally on narrow screens via overflow-x:auto wrapper. --}}
<section style="max-width: 1100px; margin: 4rem auto; padding: 0 2rem;">
    <h2 style="font-size:1.6rem; font-weight:700; text-align:center; margin-bottom:2rem; color:#f3f4f6;">
        Compare All Features
    </h2>
    <div style="overflow-x:auto; border-radius:14px; border:1px solid rgba(139,92,246,0.12); background:rgba(255,255,255,0.02);">
        <table style="width:100%; border-collapse:collapse; font-size:0.88rem; min-width:600px;">
            <thead>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.15);">
                    <th style="text-align:left; padding:1rem 1.25rem; color:#9ca3af; font-weight:600; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em;">Feature</th>
                    <th style="text-align:center; padding:1rem 1.25rem; color:#9ca3af; font-weight:600; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em;">Free</th>
                    <th style="text-align:center; padding:1rem 1.25rem; color:#a78bfa; font-weight:700; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em;">Pro</th>
                    <th style="text-align:center; padding:1rem 1.25rem; color:#9ca3af; font-weight:600; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.05em;">Studio</th>
                </tr>
            </thead>
            <tbody>
                @php
                    // Helper: renders a checkmark, dash, or value cell.
                    // $val can be: true (check), false (dash), or a string (literal).
                    $cell = function($val) {
                        if ($val === true) {
                            return '<span style="color:#8b5cf6;" aria-label="Yes">&#10003;</span>';
                        }
                        if ($val === false) {
                            return '<span style="color:#4b5563;" aria-label="No">&ndash;</span>';
                        }
                        return '<span style="color:#d1d5db;">' . e($val) . '</span>';
                    };
                @endphp

                {{-- Row: Galleries --}}
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Galleries</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('1') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('5') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('Unlimited') !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Total images</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('10') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('100') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('500') !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">3D venues</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('2') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('7') !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell('All 11') !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Custom domain</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">White-label branding</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">No Exospace watermark</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Background music</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Exhibition scheduling</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Guided tour</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Analytics dashboard</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">PIN protection</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06); background:rgba(255,255,255,0.01);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Team collaboration</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(139,92,246,0.06);">
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Priority support</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
                <tr>
                    <td style="padding:0.85rem 1.25rem; color:#d1d5db; font-weight:500;">Dedicated account manager</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(false) !!}</td>
                    <td style="padding:0.85rem 1.25rem; text-align:center;">{!! $cell(true) !!}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p style="text-align:center; font-size:0.75rem; color:#6b7280; margin-top:1rem;">
        All plans include: SSL encryption, GDPR-compliant data export, CAN-SPAM unsubscribe, and EXIF metadata stripping.
    </p>
</section>

<!-- FAQ -->
<section class="faq">
    <h2>Questions?</h2>
    <details class="faq-item">
        <summary>Is there a free trial for Pro? <span class="plus">+</span></summary>
        <p>Yes — the Free plan lets you build a real gallery with the full 3D viewer (1 gallery, 10 images, two venues), and registered Free users can start a <strong>14-day Pro trial</strong> with no card required. You can also explore Pro and Studio anytime with the paid one-time or monthly plans.</p>
    </details>
    <details class="faq-item">
        {{-- ITERATION-5: subscriptions are a real product (M-1) — the page
             needs an explicit answer instead of claiming they don't exist. --}}
        <summary>Do I have to subscribe? <span class="plus">+</span></summary>
        <p>No. Every plan is available as a <strong>one-time purchase with lifetime access</strong>. If you prefer a lower upfront cost, Pro and Studio are also available as optional monthly subscriptions — same features, cancel anytime from your billing page.</p>
    </details>
    <details class="faq-item">
        <summary>Can I upgrade later? <span class="plus">+</span></summary>
        <p>Yes. Start Free and upgrade to Pro or Studio at any time. Your existing gallery and images carry over instantly.</p>
    </details>
    <details class="faq-item">
        <summary>What payment methods do you accept? <span class="plus">+</span></summary>
        <p>We accept all major credit cards, PayPal, and other methods available through 2Checkout at checkout.</p>
    </details>
    <details class="faq-item">
        <summary>What happens to my gallery if I don't upgrade? <span class="plus">+</span></summary>
        <p>Nothing. Your gallery stays live and public. The only difference is the small "Created with Exospace" watermark in the corner.</p>
    </details>
    <details class="faq-item">
        <summary>Are the 3D venues pre-built or can I customize them? <span class="plus">+</span></summary>
        <p>Each venue is a fully-realized 3D environment with its own architecture, lighting, and atmosphere. Within a venue, you can customize wall material, floor material, frame style, and lighting preset to fine-tune the look.</p>
    </details>
</section>

<!-- Pro Upgrade Modal -->
<div id="upgrade-modal-pro" role="dialog" aria-modal="true" aria-labelledby="modal-pro-title" style="display:none;" class="fixed inset-0 z-[60] items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
    {{-- ITERATION-4: panel converted from inline styles to kit-aligned utilities. --}}
    <div class="bg-ink-900 border border-gray-700/60 rounded-2xl p-8 sm:p-10 max-w-[440px] w-[90%] text-center">

        <h3 id="modal-pro-title" class="text-xl font-bold text-gray-100 mb-2">Upgrade to Pro — $29</h3>
        <p class="text-sm text-gray-500 mb-2 leading-relaxed">One-time payment. Lifetime access.</p>
        <ul class="text-left text-[13px] text-gray-400 my-5 ps-5 leading-8 list-disc">
            <li>5 galleries · 100 images total</li>
            <li>7 venues: White Cube, Infinite Void, Industrial Loft, Dark Museum, Zen Gallery, Crystal Cathedral, Nebula Drift</li>
            <li>Background music & exhibition scheduling</li>
            <li>No watermark</li>
            <li>Priority email support</li>
        </ul>
        @auth
        <a href="{{ route('billing.upgrade', 'pro') }}" class="btn btn-primary w-full mb-3">
            Pay Securely with 2Checkout →
        </a>
        @if($hasRecurringPro)
        {{-- ITERATION-5: same monthly alternative the billing portal offers (M-1). --}}
        <a href="{{ route('billing.upgrade', 'pro') }}?recurring=1" class="btn btn-secondary w-full mb-3">
            or ${{ $recurringProPrice }}/month — cancel anytime
        </a>
        @endif
        @else
        {{-- CONV-6: Direct deep-link to register with ?redirect=billing/upgrade/pro.
             After registration + email verification, the user lands directly on
             the 2Checkout checkout page — 1 fewer step than the previous flow
             (register → log in → find pricing → click upgrade). --}}
        <a href="{{ route('register') }}?redirect={{ urlencode('billing/upgrade/pro') }}" class="btn btn-primary w-full mb-3">
            Sign up to Upgrade →
        </a>
        <p class="text-xs text-gray-500 mt-2">Already have an account? <a href="{{ route('login') }}?redirect={{ urlencode('billing/upgrade/pro') }}" class="text-brand-400 underline">Log in</a></p>
        @endauth
        <p class="text-xs text-gray-500 mt-2">Your plan activates automatically after payment — no manual steps required.</p>
        <button type="button" data-click="closeModal" data-arg="upgrade-modal-pro" class="btn btn-ghost btn-sm mt-4">Cancel</button>
    </div>
</div>

<!-- Studio Upgrade Modal -->
<div id="upgrade-modal-studio" role="dialog" aria-modal="true" aria-labelledby="modal-studio-title" style="display:none;" class="fixed inset-0 z-[60] items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
    <div class="bg-ink-900 border border-gray-700/60 rounded-2xl p-8 sm:p-10 max-w-[440px] w-[90%] text-center">

        <h3 id="modal-studio-title" class="text-xl font-bold text-gray-100 mb-2">Upgrade to Studio — $99</h3>
        <p class="text-sm text-gray-500 mb-2 leading-relaxed">One-time payment. Lifetime access.</p>
        <ul class="text-left text-[13px] text-gray-400 my-5 ps-5 leading-8 list-disc">
            <li>Unlimited galleries · 500 images total</li>
            <li>All 11 venues including Penthouse, Cyber Gallery, Sculpture Garden, Mirror Lake</li>
            <li>Custom domain (yourname.com)</li>
            <li>White-label branding & logo on every gallery</li>
            <li>Advanced analytics · team collaboration</li>
            <li>Dedicated account manager</li>
        </ul>
        @auth
        <a href="{{ route('billing.upgrade', 'studio') }}" class="btn btn-primary w-full mb-3">
            Pay Securely with 2Checkout →
        </a>
        @if($hasRecurringStudio)
        {{-- ITERATION-5: same monthly alternative the billing portal offers (M-1). --}}
        <a href="{{ route('billing.upgrade', 'studio') }}?recurring=1" class="btn btn-secondary w-full mb-3">
            or ${{ $recurringStudioPrice }}/month — cancel anytime
        </a>
        @endif
        @else
        {{-- CONV-6: Same deep-link pattern as Pro modal above. --}}
        <a href="{{ route('register') }}?redirect={{ urlencode('billing/upgrade/studio') }}" class="btn btn-primary w-full mb-3">
            Sign up to Upgrade →
        </a>
        <p class="text-xs text-gray-500 mt-2">Already have an account? <a href="{{ route('login') }}?redirect={{ urlencode('billing/upgrade/studio') }}" class="text-brand-400 underline">Log in</a></p>
        @endauth
        <p class="text-xs text-gray-500 mt-2">Your plan activates automatically after payment — no manual steps required.</p>
        <button type="button" data-click="closeModal" data-arg="upgrade-modal-studio" class="btn btn-ghost btn-sm mt-4">Cancel</button>
    </div>
</div>

{{-- openModalAnchor moved into the canonical bundle (resources/js/app.js,
    ITERATION-4) — it is a generic data-click helper, not pricing-specific. --}}

{{-- I-2 FIX (Iter-013): Product + FAQPage JSON-LD for rich results in Google SERPs.
    Renders price snippets (Free/Pro/Studio) + FAQ accordion directly in
    search results. Without these schemas, Google won't show the FAQ
    accordion or the price range in SERPs. --}}
<x-json-ld type="product" :product="['name' => 'Pro', 'price' => 29.00, 'currency' => 'USD', 'description' => 'Exospace Pro plan — 5 galleries with 100 images total, 7 venues, background music, exhibition scheduling, watermark-free galleries.']" />
<x-json-ld type="product" :product="['name' => 'Studio', 'price' => 99.00, 'currency' => 'USD', 'description' => 'Exospace Studio plan — everything in Pro plus priority support and white-label branding.']" />
{{-- ITERATION-1 FIX: the escaped quotes inside the inline :faqs attribute --}}
{{-- silently broke the component's expression evaluation — the FAQPage --}}
{{-- schema never rendered. Build the array in a PHP block and pass the variable. --}}
@php
    $pricingFaqs = [
        ['question' => 'Is there a free trial for Pro?', 'answer' => 'The Free plan lets you build a real gallery with the full 3D viewer, and registered Free users can start a 14-day Pro trial with no card required.'],
        ['question' => 'Do I have to subscribe?', 'answer' => 'No. Every plan is available as a one-time purchase with lifetime access. Pro and Studio are also available as optional monthly subscriptions with the same features — cancel anytime.'],
        ['question' => 'Can I upgrade later?', 'answer' => 'Yes. Start Free and upgrade to Pro or Studio at any time. Your existing gallery and images carry over instantly.'],
        ['question' => 'What payment methods do you accept?', 'answer' => 'We accept all major credit cards, PayPal, and other methods available through 2Checkout at checkout.'],
        ['question' => 'What happens to my gallery if I don\'t upgrade?', 'answer' => 'Nothing. Your gallery stays live and public. The only difference is the small "Created with Exospace" watermark in the corner.'],
        ['question' => 'Are the 3D venues pre-built or can I customize them?', 'answer' => 'Each venue is a fully-realized 3D environment with its own architecture, lighting, and atmosphere. Within a venue, you can customize wall material, floor material, frame style, and lighting preset to fine-tune the look.'],
    ];
@endphp
<x-json-ld type="faq-page" :faqs="$pricingFaqs" />

@endsection

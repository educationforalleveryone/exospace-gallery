{{-- P1-14 FIX (audit): Converted from standalone HTML to @extends('layouts.public').
    Previously this page had its own <html>, <head>, <nav>, no SEO meta, no
    shared footer, no cookie banner, no toast system, no PWA service worker.
    Now it inherits all of those from the public layout while keeping the
    custom pricing card CSS inline in the content section. --}}
@extends('layouts.public')

@section('title', 'Pricing — Exospace 3D Gallery')
@section('description', 'Create museum-quality 3D art exhibitions. Free to start. Pro $29 one-time. Studio $99 one-time with custom domains and white-label branding.')

@php
    // CONV-3: Determine the current user's plan for "already on this plan" state
    $currentPlan = auth()->check() ? auth()->user()->plan : null;
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
        color: #f1f5f9;
    }
    .grad {
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .pricing-hero p {
        font-size: 1.05rem; color: #94a3b8; line-height: 1.6;
        max-width: 600px; margin: 0 auto;
    }

    .pricing-cards {
        display: grid; gap: 1.5rem;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        max-width: 1200px; margin: 3rem auto; padding: 0 2rem;
    }
    .pricing-card {
        background: linear-gradient(180deg, #131319 0%, #0d0d14 100%);
        border: 1px solid rgba(139, 92, 246, 0.12);
        border-radius: 18px; padding: 2rem;
        display: flex; flex-direction: column;
        transition: all 0.3s ease;
    }
    .pricing-card:hover { border-color: rgba(139, 92, 246, 0.3); transform: translateY(-4px); }
    .pricing-card.featured {
        border-color: rgba(139, 92, 246, 0.4);
        box-shadow: 0 0 50px rgba(139, 92, 246, 0.15);
        position: relative;
    }
    .pricing-card.featured::before {
        content: 'MOST POPULAR';
        position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.1em;
        padding: 4px 12px; border-radius: 999px;
    }
    .card-tier {
        font-size: 0.85rem; font-weight: 600; color: #94a3b8;
        text-transform: uppercase; letter-spacing: 0.1em;
        margin-bottom: 0.5rem;
    }
    .card-price {
        display: flex; align-items: baseline; gap: 0.25rem;
        margin-bottom: 1rem;
    }
    .dollar { font-size: 1.5rem; color: #94a3b8; font-weight: 600; }
    .amount { font-size: 3rem; font-weight: 800; color: #f1f5f9; }
    .period { font-size: 0.85rem; color: #64748b; }
    .card-desc { font-size: 0.9rem; color: #94a3b8; line-height: 1.6; margin-bottom: 1.5rem; }
    .features { list-style: none; flex-grow: 1; margin-bottom: 1.5rem; }
    .features li {
        display: flex; align-items: flex-start; gap: 0.65rem;
        font-size: 0.85rem; color: #cbd5e1; line-height: 1.5;
        padding: 0.4rem 0;
    }
    .features li.dim { color: #475569; }
    .features li strong { color: #f1f5f9; font-weight: 600; }
    .icon { flex-shrink: 0; width: 16px; height: 16px; margin-top: 2px; }
    .feat-group { display: flex; flex-direction: column; gap: 1px; }
    .feat-label { font-weight: 600; color: #f1f5f9; }
    .feat-detail { font-size: 0.75rem; color: #64748b; }

    .btn {
        display: block; text-align: center; text-decoration: none;
        padding: 0.85rem 1.5rem; border-radius: 10px;
        font-weight: 700; font-size: 0.95rem;
        transition: all 0.2s ease;
    }
    .btn-primary {
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white;
    }
    .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(139, 92, 246, 0.35); }
    .btn-outline {
        background: transparent; color: #f1f5f9;
        border: 1px solid rgba(139, 92, 246, 0.4);
    }
    .btn-outline:hover { background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.7); }

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
        font-size: 0.8rem; color: #64748b; line-height: 1.7;
    }

    .faq {
        max-width: 720px; margin: 4rem auto; padding: 0 2rem;
    }
    .faq h2 {
        font-size: 1.6rem; font-weight: 700; text-align: center; margin-bottom: 2rem;
        color: #f1f5f9;
    }
    .faq-item {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(139, 92, 246, 0.1);
        border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 0.6rem;
    }
    .faq-item summary {
        font-size: 0.9rem; font-weight: 600; color: #f1f5f9;
        cursor: pointer; list-style: none;
        display: flex; justify-content: space-between; align-items: center;
    }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary .plus { color: #64748b; font-size: 1.2rem; transition: transform 0.2s; }
    .faq-item[open] summary .plus { transform: rotate(45deg); }
    .faq-item p {
        font-size: 0.82rem; color: #94a3b8; line-height: 1.7; padding-top: 0.6rem;
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
        <span style="font-size:0.8rem;color:#94a3b8;">Loved by artists worldwide</span>
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
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#475569" stroke-width="2.5"><line x1="3" y1="3" x2="9" y2="9"/><line x1="9" y1="3" x2="3" y2="9"/></svg></span>
                "Created with Exospace" watermark
            </li>
        </ul>
        <a href="{{ $currentPlan ? route('admin.dashboard') : route('register') }}" class="btn btn-outline">
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
        <p class="card-desc">For serious creators. More venues, more images, no watermark.</p>
        <ul class="features">
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>5 galleries</strong> · 100 images each
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
        <span class="btn btn-primary" style="opacity:0.7;cursor:default;pointer-events:none;">Your Current Plan ✓</span>
        @elseif($currentPlan === 'studio')
        <span class="btn btn-primary" style="opacity:0.7;cursor:default;pointer-events:none;">Included in Studio ✓</span>
        @else
        <a href="#" class="btn btn-primary" onclick="document.getElementById('upgrade-modal-pro').style.display='flex'; return false;">Upgrade to Pro — $29</a>
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
        <p class="card-desc">For agencies and professionals. Every venue, custom domains, white-label branding.</p>
        <ul class="features">
            <li>
                <span class="icon"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Unlimited galleries · 500 images each</strong>
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
        <span class="btn btn-outline" style="opacity:0.7;cursor:default;pointer-events:none;">Your Current Plan ✓</span>
        @else
        <a href="#" class="btn btn-outline" onclick="document.getElementById('upgrade-modal-studio').style.display='flex'; return false;">Upgrade to Studio — $99</a>
        @endif
    </div>
</div>

<!-- Trust footer -->
<div class="trust-footer">
    <div class="lock-row">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span style="font-size:0.78rem; color:#475569; font-weight:500;">Secure Payment</span>
    </div>
    <p>
        Transactions processed securely via <strong>2Checkout</strong>.<br>
        Instant digital access granted upon payment. No subscription. No recurring charges.
    </p>
</div>

<!-- FAQ -->
<section class="faq">
    <h2>Questions?</h2>
    <details class="faq-item">
        <summary>Is there a free trial for Pro? <span class="plus">+</span></summary>
        <p>The Free plan is your trial — create a gallery, upload images, and experience the full 3D viewer with two venues. Upgrade anytime when you're ready.</p>
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
<div id="upgrade-modal-pro" role="dialog" aria-modal="true" aria-labelledby="modal-pro-title" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#131319; border:1px solid #2e2e44; border-radius:16px; padding:2.5rem; max-width:440px; width:90%; text-align:center;">

        <h3 id="modal-pro-title" style="font-size:1.3rem; font-weight:700; color:#f1f5f9; margin-bottom:0.5rem;">Upgrade to Pro — $29</h3>
        <p style="font-size:0.85rem; color:#64748b; margin-bottom:0.5rem; line-height:1.6;">One-time payment. Lifetime access. No subscription.</p>
        <ul style="text-align:left; font-size:0.82rem; color:#94a3b8; margin:1.25rem 0 1.75rem; padding-left:1.25rem; line-height:2;">
            <li>5 galleries · 100 images each</li>
            <li>7 venues: White Cube, Infinite Void, Industrial Loft, Dark Museum, Zen Gallery, Crystal Cathedral, Nebula Drift</li>
            <li>Background music & exhibition scheduling</li>
            <li>No watermark</li>
            <li>Priority email support</li>
        </ul>
        @auth
        <a href="{{ route('billing.upgrade', 'pro') }}"
           style="display:block; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; text-decoration:none; padding:0.85rem 1.5rem; border-radius:10px; font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">
            Pay Securely with 2Checkout →
        </a>
        @else
        <a href="{{ route('register') }}"
           style="display:block; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; text-decoration:none; padding:0.85rem 1.5rem; border-radius:10px; font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">
            Sign up to Upgrade →
        </a>
        <p style="font-size:0.72rem; color:#475569; margin-top:0.5rem;">Already have an account? <a href="{{ route('login') }}" style="color:#8b5cf6; text-decoration:underline;">Log in</a></p>
        @endauth
        <p style="font-size:0.72rem; color:#475569;">Your plan activates automatically after payment — no manual steps required.</p>
        <button onclick="document.getElementById('upgrade-modal-pro').style.display='none'" style="margin-top:1rem; background:transparent; border:none; color:#475569; font-size:0.8rem; cursor:pointer;">Cancel</button>
    </div>
</div>

<!-- Studio Upgrade Modal -->
<div id="upgrade-modal-studio" role="dialog" aria-modal="true" aria-labelledby="modal-studio-title" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#131319; border:1px solid #2e2e44; border-radius:16px; padding:2.5rem; max-width:440px; width:90%; text-align:center;">

        <h3 id="modal-studio-title" style="font-size:1.3rem; font-weight:700; color:#f1f5f9; margin-bottom:0.5rem;">Upgrade to Studio — $99</h3>
        <p style="font-size:0.85rem; color:#64748b; margin-bottom:0.5rem; line-height:1.6;">One-time payment. Lifetime access. No subscription.</p>
        <ul style="text-align:left; font-size:0.82rem; color:#94a3b8; margin:1.25rem 0 1.75rem; padding-left:1.25rem; line-height:2;">
            <li>Unlimited galleries · 500 images each</li>
            <li>All 11 venues including Penthouse, Cyber Gallery, Sculpture Garden, Mirror Lake</li>
            <li>Custom domain (yourname.com)</li>
            <li>White-label branding & logo on every gallery</li>
            <li>Advanced analytics · team collaboration</li>
            <li>Dedicated account manager</li>
        </ul>
        @auth
        <a href="{{ route('billing.upgrade', 'studio') }}"
           style="display:block; background:linear-gradient(135deg,#f59e0b,#ef4444); color:#fff; text-decoration:none; padding:0.85rem 1.5rem; border-radius:10px; font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">
            Pay Securely with 2Checkout →
        </a>
        @else
        <a href="{{ route('register') }}"
           style="display:block; background:linear-gradient(135deg,#f59e0b,#ef4444); color:#fff; text-decoration:none; padding:0.85rem 1.5rem; border-radius:10px; font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">
            Sign up to Upgrade →
        </a>
        <p style="font-size:0.72rem; color:#475569; margin-top:0.5rem;">Already have an account? <a href="{{ route('login') }}" style="color:#f59e0b; text-decoration:underline;">Log in</a></p>
        @endauth
        <p style="font-size:0.72rem; color:#475569;">Your plan activates automatically after payment — no manual steps required.</p>
        <button onclick="document.getElementById('upgrade-modal-studio').style.display='none'" style="margin-top:1rem; background:transparent; border:none; color:#475569; font-size:0.8rem; cursor:pointer;">Cancel</button>
    </div>
</div>
@endsection

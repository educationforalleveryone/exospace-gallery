@extends('layouts.guest')

@section('content')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing — Exospace 3D Gallery</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            min-height: 100vh;
            background: #0a0a0f;
            color: #e2e8f0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* ── Nav ── */
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .nav-logo {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.2em;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        .nav-links a {
            color: #94a3b8;
            text-decoration: none;
            margin-left: 1.75rem;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .nav-links a:hover { color: #fff; }

        /* ── Hero ── */
        .hero {
            text-align: center;
            padding: 5rem 1.5rem 3rem;
        }
        .hero h1 {
            font-size: clamp(2.25rem, 5vw, 3.25rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 1rem;
        }
        .hero h1 .grad {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero p {
            color: #64748b;
            font-size: 1.05rem;
            max-width: 540px;
            margin: 0 auto;
        }

        /* ── Delivery badge ── */
        .delivery-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(59,130,246,0.08);
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 999px;
            padding: 0.4rem 1rem;
            margin-bottom: 1.75rem;
            font-size: 0.8rem;
            color: #60a5fa;
            font-weight: 500;
        }
        .delivery-badge svg { flex-shrink: 0; }

        /* ── Cards grid ── */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            max-width: 1050px;
            margin: 0 auto;
            padding: 0 1.5rem 4rem;
        }
        .card {
            background: #131319;
            border: 1px solid #1e1e2e;
            border-radius: 16px;
            padding: 2rem 1.75rem;
            position: relative;
            transition: border-color 0.25s;
        }
        .card:hover { border-color: #2e2e44; }
        .card.featured {
            border-color: #8b5cf6;
            background: linear-gradient(180deg, #1a1428 0%, #131319 40%);
        }
        .card.featured::before {
            content: 'Most Popular';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 0.3rem 1rem;
            border-radius: 999px;
            white-space: nowrap;
        }

        .card-tier {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }
        .card-price {
            display: flex;
            align-items: baseline;
            gap: 0.25rem;
            margin-bottom: 0.25rem;
        }
        .card-price .dollar { font-size: 1.25rem; color: #64748b; margin-top: 0.4rem; }
        .card-price .amount { font-size: 2.75rem; font-weight: 800; color: #f1f5f9; }
        .card-price .period { font-size: 0.85rem; color: #64748b; }
        .card-desc {
            font-size: 0.82rem;
            color: #64748b;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }

        /* ── Feature list ── */
        .features { list-style: none; margin-bottom: 2rem; }
        .features li {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.4rem 0;
            font-size: 0.87rem;
            color: #cbd5e1;
        }
        .features li .icon {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
        }
        .features li .icon.yes { background: rgba(139,92,246,0.15); }
        .features li .icon.no  { background: rgba(100,116,139,0.12); }
        .features li .icon svg { width: 10px; height: 10px; }
        .features li.dim { color: #475569; }

        /* ── CTA buttons ── */
        .btn {
            display: block;
            width: 100%;
            text-align: center;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #2e2e44;
            color: #cbd5e1;
        }
        .btn-outline:hover { border-color: #8b5cf6; color: #fff; }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            box-shadow: 0 4px 24px rgba(139,92,246,0.25);
        }
        .btn-primary:hover { box-shadow: 0 4px 32px rgba(139,92,246,0.4); transform: translateY(-1px); }

        /* ── Trust footer ── */
        .trust-footer {
            text-align: center;
            padding: 2rem 1.5rem 4rem;
            border-top: 1px solid #1e1e2e;
            max-width: 680px;
            margin: 0 auto;
        }
        .trust-footer .lock-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .trust-footer .lock-row svg { color: #64748b; }
        .trust-footer p {
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.7;
        }
        .trust-footer p strong { color: #64748b; }

        /* ── FAQ (minimal) ── */
        .faq {
            max-width: 680px;
            margin: 0 auto;
            padding: 0 1.5rem 5rem;
        }
        .faq h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.25rem;
            text-align: center;
        }
        .faq-item {
            border-bottom: 1px solid #1e1e2e;
            padding: 1rem 0;
        }
        .faq-item summary {
            font-size: 0.88rem;
            font-weight: 600;
            color: #cbd5e1;
            cursor: pointer;
            list-style: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary .plus { color: #64748b; font-size: 1.2rem; transition: transform 0.2s; }
        .faq-item[open] summary .plus { transform: rotate(45deg); }
        .faq-item p {
            font-size: 0.82rem;
            color: #64748b;
            line-height: 1.7;
            padding-top: 0.6rem;
        }
    </style>
</head>
<body>

<!-- Nav -->
<nav>
    <a href="/" class="nav-logo">EXOSPACE</a>
    <div class="nav-links">
        <a href="/">Home</a>
        <a href="/gallery/demo">Demo</a>
        <a href="/contact">Contact</a>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="delivery-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Instant digital delivery via browser
    </div>
    <h1>Simple pricing.<br><span class="grad">Powerful galleries.</span></h1>
    <p>Pick the plan that fits. No hidden fees, no credit card required to start.</p>
</section>

<!-- Cards -->
<div class="cards">

    <!-- FREE -->
    <div class="card">
        <div class="card-tier">Free</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">0</span>
            <span class="period">forever</span>
        </div>
        <p class="card-desc">Try Exospace with one gallery. Perfect for portfolios and personal projects.</p>
        <ul class="features">
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                1 gallery
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Up to 10 images
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                All 3 lighting presets
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Shareable public link
            </li>
            <li class="dim">
                <span class="icon no"><svg viewBox="0 0 12 12" fill="none" stroke="#475569" stroke-width="2.5"><line x1="3" y1="3" x2="9" y2="9"/><line x1="9" y1="3" x2="3" y2="9"/></svg></span>
                "Created with Exospace" watermark
            </li>
        </ul>
        <a href="{{ route('register') }}" class="btn btn-outline">Get Started Free</a>
    </div>

    <!-- PRO (featured) -->
    <div class="card featured">
        <div class="card-tier">Pro</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">29</span>
            <span class="period">/ one-time</span>
        </div>
        <p class="card-desc">For creators who need more. Unlimited galleries, no watermark, priority support.</p>
        <ul class="features">
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Unlimited galleries</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Up to 50 images per gallery</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                All 3 lighting presets
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Shareable public link
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Watermark removed</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Priority email support
            </li>
        </ul>
        <a href="#" class="btn btn-primary" onclick="document.getElementById('upgrade-modal').style.display='flex'; return false;">Upgrade to Pro — $29</a>
    </div>

    <!-- STUDIO -->
    <div class="card">
        <div class="card-tier">Studio</div>
        <div class="card-price">
            <span class="dollar">$</span>
            <span class="amount">99</span>
            <span class="period">/ one-time</span>
        </div>
        <p class="card-desc">For agencies and professionals. Everything in Pro, plus advanced features.</p>
        <ul class="features">
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Unlimited galleries</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Unlimited images per gallery</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                All 3 lighting presets
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                Shareable public link
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Watermark removed</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Dedicated account manager</strong>
            </li>
            <li>
                <span class="icon yes"><svg viewBox="0 0 12 12" fill="none" stroke="#8b5cf6" stroke-width="2.5"><polyline points="2,6 5,9 10,3"/></svg></span>
                <strong>Custom branding on gallery</strong>
            </li>
        </ul>
        <a href="#" class="btn btn-outline" onclick="document.getElementById('upgrade-modal').style.display='flex'; return false;">Upgrade to Studio — $99</a>
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
        <p>The Free plan is your trial — create a gallery, upload images, and experience the full 3D viewer. Upgrade anytime when you're ready.</p>
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
</section>

<!-- Upgrade Modal (placeholder for 2Checkout integration) -->
<div id="upgrade-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#131319; border:1px solid #2e2e44; border-radius:16px; padding:2.5rem; max-width:420px; width:90%; text-align:center;">
        <div style="width:48px; height:48px; border-radius:12px; background:linear-gradient(135deg,#3b82f6,#8b5cf6); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <h3 style="font-size:1.25rem; font-weight:700; color:#f1f5f9; margin-bottom:0.5rem;">2Checkout Integration</h3>
        <p style="font-size:0.82rem; color:#64748b; margin-bottom:1.5rem; line-height:1.6;">Payment processing via 2Checkout is currently being integrated. We'll be accepting payments shortly.</p>
        <p style="font-size:0.78rem; color:#475569; margin-bottom:1.5rem;">In the meantime, <a href="/contact" style="color:#a78bfa; text-decoration:none;">contact us</a> to request early access or manual upgrade.</p>
        <button onclick="document.getElementById('upgrade-modal').style.display='none'" style="background:#1e1e2e; border:1px solid #2e2e44; color:#cbd5e1; padding:0.6rem 1.5rem; border-radius:8px; font-size:0.85rem; cursor:pointer; font-family:inherit;">Close</button>
    </div>
</div>

</body>
</html>
@endsection
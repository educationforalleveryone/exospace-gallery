@extends('layouts.public')
@section('title', 'Contact — Exospace Gallery')
@section('description', 'Get in touch with the Exospace Gallery team — support, billing, partnerships, and general inquiries. We respond within 12-24 hours.')

@section('content')

<!-- H-1 FIX (Iter-012): Contact page now extends layouts.public for shared nav, footer, SEO meta, cookie banner, and skip-link. Custom styles are scoped to this page only. -->
<style>
        /* ── Page-scoped styles only (ITERATION-4).
           The previous `* { margin:0; padding:0 }` reset and `body {}` rules
           applied to the WHOLE DOCUMENT — overriding the public layout's
           ink canvas, text color and list rhythm on this page. They are gone;
           the shared layout owns the canvas, this block owns only the
           page's own classes. ── */

        /* ── Page layout ── */
        .page {
            max-width: 900px;
            margin: 0 auto;
            padding: 4rem 1.5rem 6rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3.5rem;
            align-items: start;
        }
        @media (max-width: 640px) {
            .page { grid-template-columns: 1fr; gap: 2.5rem; padding-top: 2.5rem; }
        }

        /* ── Left: Info ── */
        .info-col h1 {
            font-size: clamp(1.75rem, 4vw, 2.25rem);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 0.75rem;
        }
        .info-col h1 .grad {
            background: linear-gradient(135deg, #a78bfa, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .info-col > p {
            font-size: 0.88rem;
            color: #6b7280;
            line-height: 1.7;
            margin-bottom: 2.25rem;
        }

        .info-card {
            background: #0f1117; /* ink-900 */
            border: 1px solid #1f2937; /* gray-800 */
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 0.75rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        .info-card .icon-wrap {
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(139,92,246,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .info-card .icon-wrap svg { color: #8b5cf6; }
        .info-card .label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.2rem;
        }
        .info-card .value {
            font-size: 0.88rem;
            color: #d1d5db;
            line-height: 1.5;
        }
        .info-card .value a {
            color: #a78bfa;
            text-decoration: none;
        }
        .info-card .value a:hover { text-decoration: underline; }

        /* ── Right: Form ── */
        .form-col {
            background: #0f1117; /* ink-900 */
            border: 1px solid #1f2937; /* gray-800 */
            border-radius: 16px;
            padding: 2rem;
        }
        .form-col h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f3f4f6;
            margin-bottom: 1.5rem;
        }
        .field { margin-bottom: 1.25rem; }
        .field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.4rem;
        }
        /* ITERATION-4: field skin deleted — inputs use the shared .input-base
           recipe (inset well, brand focus ring) from app.css. The old skin
           set outline:none and replaced the global focus ring with a border
           color change only — weaker than the system's ring. */

        /* ITERATION-4: .btn-submit deleted — the kit .btn-primary is the
           canonical submit CTA (blue gradient retired). */

        /* ── Success message ── */
        .success-msg {
            display: none;
            text-align: center;
            padding: 2rem 1rem;
        }
        .success-msg .check {
            width: 52px; height: 52px;
            border-radius: 50%;
            background: rgba(139,92,246,0.12);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
        }
        .success-msg h3 { font-size: 1.1rem; color: #f3f4f6; margin-bottom: 0.4rem; }
        .success-msg p { font-size: 0.82rem; color: #6b7280; line-height: 1.6; }

        /* ── Validation ── */
        .error-text { font-size: 0.75rem; color: #f87171; margin-top: 0.3rem; display: none; }
        .field.has-error .input-base { border-color: #f87171; }
        .field.has-error .error-text { display: block; }
    </style>

<!-- Main -->
<div class="page">

    <!-- Left -->
    <div class="info-col">
        <h1>Get in <span class="grad">touch</span></h1>
        <p>Have a question, need support, or want to discuss a custom solution? We're here to help.</p>

        <div class="info-card">
            <div class="icon-wrap">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <div>
                <div class="label">Support Email</div>
                <div class="value"><a href="mailto:support@exospace.gallery">support@exospace.gallery</a></div>
            </div>
        </div>

        <div class="info-card">
            <div class="icon-wrap">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="label">Response Time</div>
                <div class="value">We typically respond within 12–24 hours</div>
            </div>
        </div>

        <div class="info-card">
            <div class="icon-wrap">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div>
                <div class="label">Business Address</div>
                <div class="value">
                    <!-- The business address is rendered in the shared footer from EXOSPACE_BUSINESS_ADDRESS --></div>
            </div>
        </div>
    </div>

    <!-- Right -->
    <div class="form-col">
        <h2>Send us a message</h2>

        <!-- Form -->
        <div id="contact-form-wrap">
            <form id="contact-form" novalidate>
                @csrf
                <div class="field" id="field-name">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" placeholder="Your full name" required class="input-base">
                    <div class="error-text">Name is required.</div>
                </div>
                <div class="field" id="field-email">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required class="input-base">
                    <div class="error-text">Please enter a valid email address.</div>
                </div>
                <div class="field" id="field-subject">
                    <label for="subject">Subject</label>
                    <select id="subject" name="subject" required class="input-base">
                        <option value="" disabled selected>Select a topic</option>
                        <option value="general">General Inquiry</option>
                        <option value="support">Technical Support</option>
                        <option value="billing">Billing / Payment</option>
                        <option value="upgrade">Upgrade Question</option>
                        <option value="partnership">Partnership</option>
                    </select>
                    <div class="error-text">Please select a topic.</div>
                </div>
                <div class="field" id="field-message">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" placeholder="How can we help you?" required class="input-base min-h-[110px]"></textarea>
                    <div class="error-text">Please write your message.</div>
                </div>
                {{-- P3-19: Cloudflare Turnstile captcha. Renders nothing when
                     TURNSTILE_SITE_KEY is not configured (TurnstileService is
                     disabled in dev mode). In production, renders an invisible
                     widget that challenges the user only when Cloudflare
                     detects suspicious traffic. --}}
                @if(app('App\Services\TurnstileService')->isEnabled())
                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                @endif
                <button type="submit" class="btn btn-primary w-full">Send Message</button>
            </form>
        </div>

        <!-- Success state (shown after submit) -->
        <div class="success-msg" id="success-msg">
            <div class="check">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h3>Message sent!</h3>
            <p>Thank you. We'll get back to you within 12–24 hours at the email you provided.</p>
        </div>
    </div>
</div>

<script nonce="@nonce">
document.getElementById('contact-form').addEventListener('submit', function(e) {
    e.preventDefault();
    let valid = true;

    // Simple client-side validation
    ['name', 'email', 'subject', 'message'].forEach(function(id) {
        const field = document.getElementById('field-' + id);
        const input = document.getElementById(id);
        const val = input.value.trim();

        if (!val) {
            field.classList.add('has-error');
            valid = false;
        } else {
            field.classList.remove('has-error');
        }

        // Email format check
        if (id === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            field.classList.add('has-error');
            valid = false;
        }
    });

    if (!valid) return;

    // Submit via fetch (you can wire this to a ContactController later)
    const formData = new FormData(this);
    const btn = this.querySelector('button[type="submit"]');
    btn.textContent = 'Sending...';
    btn.disabled = true;

    fetch('/contact', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(async function(response) {
        // C-8 FIX (Iter-009): Previously this handler showed the success UI
        // regardless of the backend response — a 422 validation error or 500
        // server error would silently drop the lead and show 'Message sent!'.
        // Now we parse the response and branch on success/failure.
        let data = null;
        try { data = await response.json(); } catch (e) { /* not JSON */ }
        if (response.ok && data && (data.success === true || data.success === undefined)) {
            // Real success — backend returned 2xx.
            document.getElementById('contact-form-wrap').style.display = 'none';
            document.getElementById('success-msg').style.display = 'block';
            return;
        }
        // Failure path — show inline error toast and re-enable the button.
        let errorMsg = 'Your message could not be sent. ';
        if (response.status === 422 && data && data.errors) {
            // Laravel validation error — show first field error.
            const firstField = Object.keys(data.errors)[0];
            errorMsg = data.errors[firstField][0];
        } else if (response.status === 422 && data && data.error) {
            // ContactController's Turnstile-fail shape: {error: '...'}
            errorMsg = data.error;
        } else if (response.status >= 500) {
            errorMsg += 'The server had an error. Please try again or email support@exospace.gallery directly.';
        } else if (data && data.error) {
            errorMsg = data.error;
        } else if (data && data.message) {
            errorMsg += data.message;
        } else {
            errorMsg += 'Please try again.';
        }
        window.toast(errorMsg, 'error');
        btn.textContent = 'Send Message';
        btn.disabled = false;
    }).catch(function(networkErr) {
        // Network error (DNS, offline, CORS) — distinctly different from
        // a server-returned failure. Show a network-specific message.
        window.toast('Network error — please try again, or email support@exospace.gallery directly.', 'error');
        btn.textContent = 'Send Message';
        btn.disabled = false;
    });
});
/**
 * C-8 FIX: Inline error toast (no external dep). Auto-dismisses after 6s.
 * Replaces the silent 'show success on failure' behaviour that silently
 * dropped customer leads.
 */
// ITERATION-3: the page-local showErrorToast (light-theme, top-right,
// z-index 9999) was removed — window.toast() from the x-toast component is
// the single toast system on this layout.
// (FIX 2026-08-31: do not write the literal component tag here — Blade
// compiles x-tags inside JS comments too, and a non-self-closing tag with
// no closing tag broke compilation of this whole page.)
</script>

@endsection

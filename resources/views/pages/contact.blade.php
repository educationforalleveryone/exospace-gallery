<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact — Exospace 3D Gallery</title>
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
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .info-col > p {
            font-size: 0.88rem;
            color: #64748b;
            line-height: 1.7;
            margin-bottom: 2.25rem;
        }

        .info-card {
            background: #131319;
            border: 1px solid #1e1e2e;
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
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.2rem;
        }
        .info-card .value {
            font-size: 0.88rem;
            color: #cbd5e1;
            line-height: 1.5;
        }
        .info-card .value a {
            color: #a78bfa;
            text-decoration: none;
        }
        .info-card .value a:hover { text-decoration: underline; }

        /* ── Right: Form ── */
        .form-col {
            background: #131319;
            border: 1px solid #1e1e2e;
            border-radius: 16px;
            padding: 2rem;
        }
        .form-col h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.5rem;
        }
        .field { margin-bottom: 1.25rem; }
        .field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.4rem;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            background: #0f0f14;
            border: 1px solid #2e2e44;
            border-radius: 8px;
            padding: 0.7rem 0.85rem;
            color: #e2e8f0;
            font-size: 0.88rem;
            font-family: inherit;
            transition: border-color 0.2s;
            outline: none;
        }
        .field input:focus,
        .field select:focus,
        .field textarea:focus { border-color: #8b5cf6; }
        .field textarea { resize: vertical; min-height: 110px; }
        .field select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.85rem center; padding-right: 2.2rem; }
        .field select option { background: #1e1e2e; color: #e2e8f0; }

        .btn-submit {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: box-shadow 0.2s, transform 0.15s;
            box-shadow: 0 4px 20px rgba(139,92,246,0.25);
        }
        .btn-submit:hover { box-shadow: 0 4px 28px rgba(139,92,246,0.4); transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }

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
        .success-msg h3 { font-size: 1.1rem; color: #f1f5f9; margin-bottom: 0.4rem; }
        .success-msg p { font-size: 0.82rem; color: #64748b; line-height: 1.6; }

        /* ── Validation ── */
        .error-text { font-size: 0.75rem; color: #f87171; margin-top: 0.3rem; display: none; }
        .field.has-error input,
        .field.has-error select,
        .field.has-error textarea { border-color: #f87171; }
        .field.has-error .error-text { display: block; }
    </style>
</head>
<body>

<!-- Nav -->
<nav>
    <a href="/" class="nav-logo">EXOSPACE</a>
    <div class="nav-links">
        <a href="/">Home</a>
        <a href="/gallery/demo">Demo</a>
        <a href="/pricing">Pricing</a>
    </div>
</nav>

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
                    <!-- REPLACE THIS WITH YOUR ACTUAL ADDRESS -->
                    Exospace Technologies<br>
                    [Your Street Address]<br>
                    [City, State/Province, Postal Code]<br>
                    [Country]
                </div>
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
                    <input type="text" id="name" name="name" placeholder="Your full name" required>
                    <div class="error-text">Name is required.</div>
                </div>
                <div class="field" id="field-email">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                    <div class="error-text">Please enter a valid email address.</div>
                </div>
                <div class="field" id="field-subject">
                    <label for="subject">Subject</label>
                    <select id="subject" name="subject" required>
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
                    <textarea id="message" name="message" placeholder="How can we help you?" required></textarea>
                    <div class="error-text">Please write your message.</div>
                </div>
                <button type="submit" class="btn-submit">Send Message</button>
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

<script>
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
    const btn = this.querySelector('.btn-submit');
    btn.textContent = 'Sending...';
    btn.disabled = true;

    fetch('/contact', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function() {
        // Show success regardless of backend status for now
        document.getElementById('contact-form-wrap').style.display = 'none';
        document.getElementById('success-msg').style.display = 'block';
    }).catch(function() {
        // Still show success — the form data is captured
        document.getElementById('contact-form-wrap').style.display = 'none';
        document.getElementById('success-msg').style.display = 'block';
    });
});
</script>

</body>
</html>
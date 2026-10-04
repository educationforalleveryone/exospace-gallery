@extends('layouts.public')

@section('title', 'Privacy Policy — Exospace Gallery')
@section('description', 'How Exospace Gallery collects, uses, discloses, and safeguards your information when you use our service.')

@section('content')

<main class="max-w-4xl mx-auto px-4 py-12">
    <h1 class="text-4xl font-bold mb-8">Privacy Policy</h1>
    <p class="text-gray-400 mb-8">Last Updated: October 4, 2026</p>

    <div class="legal-prose">
        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">1. Introduction</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                Welcome to Exospace Gallery ("we," "our," or "us"). We are committed to protecting your personal information and your right to privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our service.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">2. Information We Collect</h2>
            <p class="text-gray-300 leading-relaxed mb-4">We collect information that you provide directly to us, including:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Account information (name, email address, password)</li>
                <li>Images and artwork you upload to create galleries</li>
                <li>Gallery settings and customization preferences</li>
                <li>Payment information (processed securely through our payment processor)</li>
                <li>Communications you send to us</li>
                <li>Pseudonymous usage analytics: gallery views, focus events and 3D tour interactions, recorded against a random per-visit session identifier that is stored only as an irreversible hash. These analytics never include your IP address, user agent, or account identity.</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">3. How We Use Your Information</h2>
            <p class="text-gray-300 leading-relaxed mb-4">We use the information we collect to:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Provide, maintain, and improve our services</li>
                <li>Process your transactions and manage your account</li>
                <li>Send you technical notices, updates, and support messages</li>
                <li>Respond to your comments and questions</li>
                <li>Analyze usage patterns to improve user experience</li>
                <li>Protect against fraudulent or illegal activity</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">4. Information Sharing</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                We do not sell, trade, or rent your personal information to third parties. We may share your information only in the following circumstances:
            </p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>With service providers who assist in operating our platform: <strong>2Checkout (a Verifone company)</strong> for payment processing, <strong>Resend</strong> for email delivery, <strong>Sentry</strong> for error monitoring, and <strong>Cloudflare</strong> for encrypted off-site backup storage</li>
                <li>When required by law or to respond to legal process</li>
                <li>To protect our rights, privacy, safety, or property</li>
                <li>In connection with a merger, acquisition, or sale of assets</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">5. Data Security</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                We implement appropriate technical and organizational measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction. However, no method of transmission over the Internet is 100% secure, and we cannot guarantee absolute security.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">6. Data Retention</h2>
            <p class="text-gray-300 leading-relaxed mb-4">We keep personal information only as long as necessary for the purposes described above:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Raw usage analytics are kept for <strong>90 days</strong> and then deleted; only aggregate daily totals (view and visitor counts) are kept afterwards.</li>
                <li>When you delete your account, your profile, galleries and uploads are removed immediately; the pseudonymous analytics recorded during your visits are removed with the regular retention cycle above.</li>
                <li>Billing records (invoices and transactions) are retained in anonymized form — the email address is replaced with an irreversible token and name/address fields are removed — to satisfy accounting and tax obligations. Personal information older than <strong>18 months</strong> is anonymized on the same schedule across feedback, RSVP, newsletter, and audit records.</li>
                <li>Encrypted backups are retained for up to <strong>2 years</strong> on a rolling schedule; restored data is subject to the same retention rules.</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">7. Your Rights</h2>
            <p class="text-gray-300 leading-relaxed mb-4">You have the right to:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Access, update, or delete your personal information</li>
                <li>Object to processing of your personal information</li>
                <li>Request restriction of processing your personal information</li>
                <li>Data portability</li>
                <li>Withdraw consent at any time</li>
            </ul>
            <p class="text-gray-300 leading-relaxed mb-4 mt-4">
                To exercise any of these rights, visit your <a href="{{ route('profile.edit') }}" class="text-brand-400 hover:text-brand-300">profile settings</a> to update or delete your account, or <a href="{{ route('profile.export') }}" class="text-brand-400 hover:text-brand-300">download your data</a> for portability.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">8. Cookies and Tracking</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                We use cookies and similar tracking technologies to keep you signed in, remember your preferences, and understand how galleries are used. Our cookie banner lets you accept or decline non-essential cookies; your choice is stored in a <code>exospace_cookie_consent</code> cookie, and when you decline, the anonymous gallery-analytics beacon is switched off both in your browser and on our servers. Anonymous view counting for an exhibition owner's public statistics continues, but it uses no tracking cookies and stores no personal identifiers.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">9. Children's Privacy</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                Our service is not intended for children under 13 years of age. We do not knowingly collect personal information from children under 13. If you become aware that a child has provided us with personal information, please contact us.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">10. Changes to This Policy</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">11. Contact Us</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                If you have any questions about this Privacy Policy, please contact us at:
            </p>
            <p class="text-brand-400">
                Email: <a href="mailto:support@exospace.gallery" class="hover:text-brand-300">support@exospace.gallery</a>
            </p>
            @if ($businessAddress = config('app.business_address'))
                <p class="text-gray-400 mt-2 whitespace-pre-line">{{ $businessAddress }}</p>
            @endif
        </section>
    </div>
</main>

@endsection

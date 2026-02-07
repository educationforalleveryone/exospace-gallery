{{-- resources/views/pages/security.blade.php --}}
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Security – Exospace Gallery</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">

    <!-- Nav -->
    <nav class="fixed w-full bg-gray-900/95 backdrop-blur-sm z-50 border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="/" class="text-2xl font-bold gradient-text">Exospace</a>
                <a href="/" class="text-sm text-gray-400 hover:text-gray-200 transition">← Back to Home</a>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <main class="max-w-3xl mx-auto px-4 pt-32 pb-24">
        <div class="mb-10">
            <h1 class="text-4xl font-bold mb-3">Payment Security</h1>
            <p class="text-gray-500 text-sm">Your financial safety is our top priority.</p>
        </div>

        <div class="space-y-8 text-gray-300 leading-relaxed">

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">Payments Powered by 2Checkout</h2>
                <p>All payments on Exospace are processed exclusively through <strong class="text-gray-100">2Checkout</strong> (now part of Verifone), one of the world's most trusted and secure online payment processors. We never store, handle, or have access to your credit card details at any point in the transaction.</p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">PCI DSS Compliance</h2>
                <p>2Checkout is fully compliant with the <strong class="text-gray-100">Payment Card Industry Data Security Standard (PCI DSS)</strong>. This means that all payment data is handled in accordance with the strictest international security requirements mandated by major card brands (Visa, Mastercard, American Express, and others).</p>
                <p class="mt-3">Because 2Checkout manages the entire payment form and transaction flow, Exospace's own servers are <strong class="text-gray-100">never in scope for PCI DSS</strong> — your card data never touches our infrastructure.</p>
            </section>

            <!-- Trust Badges Visual Block -->
            <section class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                <div class="grid sm:grid-cols-3 gap-4 text-center">
                    <div class="flex flex-col items-center gap-2">
                        <div class="w-12 h-12 rounded-full bg-green-900 flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <span class="text-sm text-gray-300 font-medium">PCI DSS<br>Compliant</span>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <div class="w-12 h-12 rounded-full bg-blue-900 flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3zM19 10v2a7 7 0 01-14 0v-2m7 7v4m-4 0h8" />
                            </svg>
                        </div>
                        <span class="text-sm text-gray-300 font-medium">256-bit SSL<br>Encryption</span>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <div class="w-12 h-12 rounded-full bg-purple-900 flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>
                        </div>
                        <span class="text-sm text-gray-300 font-medium">Buyer<br>Protection</span>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">SSL Encryption</h2>
                <p>All data transmitted between your browser and our servers — including login credentials and account information — is encrypted using <strong class="text-gray-100">TLS 1.2/1.3 (256-bit SSL)</strong>. You can confirm this by the padlock icon in your browser's address bar when visiting any page on exospace.gallery.</p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">Security Headers</h2>
                <p>Our servers enforce strict HTTP security headers on every response, including:</p>
                <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                    <li><strong class="text-gray-300">Strict-Transport-Security (HSTS)</strong> — Forces HTTPS for all connections.</li>
                    <li><strong class="text-gray-300">X-Content-Type-Options</strong> — Prevents MIME type sniffing attacks.</li>
                    <li><strong class="text-gray-300">X-Frame-Options</strong> — Blocks clickjacking by preventing our pages from being embedded in iframes.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">What We Don't Store</h2>
                <p>To be completely transparent, <strong class="text-gray-100">Exospace does not store</strong>: credit card numbers, CVVs, expiry dates, or any other payment card data. All payment information is tokenised and managed entirely by 2Checkout's secure infrastructure.</p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-gray-100 mb-3">Reporting a Security Issue</h2>
                <p>If you discover a security vulnerability on our platform, please report it responsibly by emailing <a href="mailto:support@exospace.gallery" class="text-purple-400 hover:text-purple-300">support@exospace.gallery</a> with the subject line <em>"Security Report"</em>. We take all reports seriously and will investigate promptly.</p>
            </section>
        </div>
    </main>

    @include('layouts.partials.footer')
</body>
</html>
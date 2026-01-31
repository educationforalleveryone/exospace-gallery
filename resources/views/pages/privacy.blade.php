<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Exospace Gallery</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-100">

    <!-- Navigation -->
    <nav class="bg-gray-900 border-b border-gray-800">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="/" class="text-2xl font-bold bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">
                    Exospace
                </a>
                <a href="/" class="text-gray-300 hover:text-white transition">Back to Home</a>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <main class="max-w-4xl mx-auto px-4 py-12">
        <h1 class="text-4xl font-bold mb-8">Privacy Policy</h1>
        <p class="text-gray-400 mb-8">Last Updated: {{ date('F d, Y') }}</p>

        <div class="prose prose-invert max-w-none">
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
                    <li>With service providers who assist in operating our platform (hosting, payment processing, analytics)</li>
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
                <h2 class="text-2xl font-semibold mb-4">6. Your Rights</h2>
                <p class="text-gray-300 leading-relaxed mb-4">You have the right to:</p>
                <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                    <li>Access, update, or delete your personal information</li>
                    <li>Object to processing of your personal information</li>
                    <li>Request restriction of processing your personal information</li>
                    <li>Data portability</li>
                    <li>Withdraw consent at any time</li>
                </ul>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold mb-4">7. Cookies and Tracking</h2>
                <p class="text-gray-300 leading-relaxed mb-4">
                    We use cookies and similar tracking technologies to track activity on our service and store certain information. You can instruct your browser to refuse all cookies or to indicate when a cookie is being sent.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold mb-4">8. Children's Privacy</h2>
                <p class="text-gray-300 leading-relaxed mb-4">
                    Our service is not intended for children under 13 years of age. We do not knowingly collect personal information from children under 13. If you become aware that a child has provided us with personal information, please contact us.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold mb-4">9. Changes to This Policy</h2>
                <p class="text-gray-300 leading-relaxed mb-4">
                    We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-semibold mb-4">10. Contact Us</h2>
                <p class="text-gray-300 leading-relaxed mb-4">
                    If you have any questions about this Privacy Policy, please contact us at:
                </p>
                <p class="text-purple-400">
                    Email: <a href="mailto:support@exospace.gallery" class="hover:text-purple-300">support@exospace.gallery</a>
                </p>
            </section>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 border-t border-gray-800 py-8 px-4 mt-12">
        <div class="max-w-4xl mx-auto text-center text-gray-500">
            <p>&copy; {{ date('Y') }} Exospace Gallery. All rights reserved.</p>
            <div class="mt-4 space-x-4">
                <a href="{{ route('privacy') }}" class="hover:text-white transition">Privacy Policy</a>
                <span>•</span>
                <a href="{{ route('terms') }}" class="hover:text-white transition">Terms of Service</a>
            </div>
        </div>
    </footer>

</body>
</html>
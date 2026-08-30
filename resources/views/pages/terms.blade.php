@extends('layouts.public')

@section('title', 'Terms of Service — Exospace Gallery')
@section('description', 'The terms and conditions for using Exospace Gallery. By using the service, you accept these terms.')

@section('content')

<main class="max-w-4xl mx-auto px-4 py-12">
    <h1 class="text-4xl font-bold mb-8">Terms of Service</h1>
    <p class="text-gray-400 mb-8">Last Updated: {{ date('F d, Y') }}</p>

    <div class="legal-prose">
        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">1. Acceptance of Terms</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                By accessing and using Exospace Gallery ("Service"), you accept and agree to be bound by the terms and provisions of this agreement. If you do not agree to these Terms of Service, please do not use our Service.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">2. Description of Service</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                Exospace Gallery provides a platform for creating and sharing immersive 3D virtual art galleries. The Service allows users to upload images, customize gallery settings, and share their exhibitions with others through web-based 3D environments.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">3. User Accounts</h2>
            <p class="text-gray-300 leading-relaxed mb-4">To use our Service, you must:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Be at least 18 years old or have parental consent</li>
                <li>Provide accurate and complete registration information</li>
                <li>Maintain the security of your account credentials</li>
                <li>Promptly update any changes to your account information</li>
                <li>Accept responsibility for all activities under your account</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">4. User Content</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                You retain all rights to the images and content you upload ("User Content"). By uploading content to our Service, you grant us a non-exclusive, worldwide, royalty-free license to host, display, and process your content solely for the purpose of providing the Service.
            </p>
            <p class="text-gray-300 leading-relaxed mb-4">You represent and warrant that:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>You own or have the necessary rights to upload and share your content</li>
                <li>Your content does not violate any third-party rights</li>
                <li>Your content does not contain illegal, harmful, or offensive material</li>
                <li>Your content complies with all applicable laws and regulations</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">5. Prohibited Uses</h2>
            <p class="text-gray-300 leading-relaxed mb-4">You agree not to:</p>
            <ul class="list-disc list-inside text-gray-300 space-y-2 ml-4">
                <li>Upload content that infringes copyright, trademark, or other intellectual property rights</li>
                <li>Upload pornographic, violent, or otherwise offensive content</li>
                <li>Use the Service for any illegal purpose</li>
                <li>Attempt to interfere with or compromise the system integrity</li>
                <li>Use automated systems to access the Service without permission</li>
                <li>Resell or redistribute the Service without authorization</li>
                <li>Impersonate any person or entity</li>
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">6. Subscription and Payment</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                Some features of the Service require a paid upgrade. Plans are available either as a one-time purchase (lifetime access) or as an optional monthly subscription. By purchasing a plan, you agree to pay all applicable fees. One-time purchases are a single payment and remain active for the lifetime of your account. Monthly subscriptions renew automatically at the end of each billing cycle until cancelled; you can cancel at any time from your billing page and will keep access until the end of the current billing cycle. All fees are non-refundable except as explicitly stated in our <a href="/refund-policy" class="text-brand-400 hover:text-brand-300">Refund Policy</a>, which includes a 14-day money-back guarantee.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">7. Cancellation and Termination</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                One-time purchases remain active for the lifetime of your account — there is nothing to cancel. Monthly subscriptions can be cancelled at any time from your billing page; cancellation stops future charges, and your plan stays active until the end of the current billing cycle, after which your account reverts to the Free plan. We reserve the right to suspend or terminate your account if you violate these Terms of Service or engage in fraudulent activity.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">8. Intellectual Property</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                The Service, including its original content, features, and functionality, is owned by Exospace Gallery and is protected by international copyright, trademark, and other intellectual property laws. You may not copy, modify, distribute, or reverse engineer any part of the Service.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">9. Disclaimer of Warranties</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, SECURE, OR ERROR-FREE.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">10. Limitation of Liability</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                TO THE MAXIMUM EXTENT PERMITTED BY LAW, EXOSPACE GALLERY SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER INCURRED DIRECTLY OR INDIRECTLY.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">11. Indemnification</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                You agree to indemnify and hold harmless Exospace Gallery from any claims, damages, losses, liabilities, and expenses arising from your use of the Service or violation of these Terms.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">12. Changes to Terms</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                We reserve the right to modify these Terms at any time. We will notify users of material changes via email or through the Service. Your continued use of the Service after such changes constitutes acceptance of the new Terms.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">13. Governing Law</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                These Terms shall be governed by and construed in accordance with applicable laws, without regard to conflict of law provisions.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">14. Contact Information</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                If you have any questions about these Terms of Service, please contact us at:
            </p>
            <p class="text-brand-400">
                Email: <a href="mailto:support@exospace.gallery" class="hover:text-brand-300">support@exospace.gallery</a>
            </p>
        </section>

        <section class="mb-8">
            <h2 class="text-2xl font-semibold mb-4">15. Severability</h2>
            <p class="text-gray-300 leading-relaxed mb-4">
                If any provision of these Terms is found to be unenforceable or invalid, that provision will be limited or eliminated to the minimum extent necessary so that the Terms will otherwise remain in full force and effect.
            </p>
        </section>
    </div>
</main>

@endsection

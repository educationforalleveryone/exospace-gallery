@extends('layouts.public')

@section('title', 'Refund Policy — Exospace Gallery')
@section('description', 'Our 14-day money-back guarantee. If you are not satisfied for any reason, request a full refund within 14 days of your purchase.')

@section('content')

<main class="max-w-3xl mx-auto px-4 pt-32 pb-24">
    <div class="mb-10">
        <h1 class="text-4xl font-bold mb-3">Refund Policy</h1>
        <p class="text-gray-500 text-sm">Last updated: January 2025</p>
    </div>

    <div class="space-y-8 text-gray-300 leading-relaxed">

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">14-Day Money-Back Guarantee</h2>
            <p>At Exospace Gallery Ltd., we want you to be completely satisfied with your purchase. If you are not satisfied for any reason, you may request a full refund within <strong class="text-gray-100">14 calendar days</strong> of your initial payment date.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">How to Request a Refund</h2>
            <p>To initiate a refund, please contact our support team at <a href="mailto:support@exospace.gallery" class="text-brand-400 hover:text-brand-300">support@exospace.gallery</a> with the subject line <em>"Refund Request"</em>. Include the following in your email:</p>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>Your registered email address or account name.</li>
                <li>The order number or transaction ID (found in your confirmation email, or in your <a href="{{ route('billing.index') }}" class="text-brand-400 hover:text-brand-300">billing portal</a>).</li>
                <li>A brief reason for the refund (optional, but helps us improve).</li>
            </ul>
            <p class="mt-3">We will process your refund request within <strong class="text-gray-100">5–7 business days</strong> of receiving it. Refunds are issued to the original payment method.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Refunds Processed via 2Checkout</h2>
            <p>All payments on Exospace are processed securely through <strong class="text-gray-100">2Checkout (a Verifone company)</strong>. Refunds issued through 2Checkout will appear on your statement within 5–10 business days depending on your financial institution.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Exceptions</h2>
            <p>Refunds will not be issued in the following cases:</p>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>The refund request is made after the 14-day window has passed.</li>
                <li>The account has been used in violation of our <a href="/terms" class="text-brand-400 hover:text-brand-300">Terms of Service</a>.</li>
                <li>The refund request is submitted more than 14 days after the original purchase date.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Lifetime Plans</h2>
            <p>All Exospace plans (Pro and Studio) are one-time lifetime purchases with no recurring billing. Refund requests made within 14 days of purchase are eligible for a <strong class="text-gray-100">full refund</strong>. No pro-rated refunds apply as there is no subscription cycle.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Contact Us</h2>
            <p>If you have any questions about this refund policy, please reach out:</p>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>Email: <a href="mailto:support@exospace.gallery" class="text-brand-400 hover:text-brand-300">support@exospace.gallery</a></li>
                <li>Phone: +92 311 234 5678</li>
                <li>Address: 27 Innovation Drive, Suite 4B, Islamabad, Islamabad Capital Territory 44000, Pakistan</li>
            </ul>
        </section>
    </div>
</main>

@endsection

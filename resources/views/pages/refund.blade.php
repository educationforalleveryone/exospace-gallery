@extends('layouts.public')

@section('title', 'Refund Policy — Exospace Gallery')
@section('description', 'Our 14-day money-back guarantee on Pro and Studio plans, how to request a refund, and how monthly subscriptions and cancellations work.')

@section('content')

<main class="max-w-3xl mx-auto px-4 pt-32 pb-24">
    <div class="mb-10">
        <h1 class="text-4xl font-bold mb-3">Refund Policy</h1>
        <p class="text-gray-500 text-sm">Last updated: October 12, 2026</p>
    </div>

    <div class="space-y-8 text-gray-300 leading-relaxed">

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">14-Day Money-Back Guarantee</h2>
            <p>{{ config('app.legal_name') }} offers a <strong class="text-gray-100">14-day money-back guarantee</strong> on every paid plan. If Pro or Studio is not right for you, you may request a full refund within <strong class="text-gray-100">14 calendar days</strong> of your payment date, no reasons required.</p>
            <p class="mt-3 text-gray-400">The Free plan and the 14-day Pro trial involve no payment, so there is nothing to refund.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">One-Time Purchases and Monthly Subscriptions</h2>
            <ul class="list-disc list-inside space-y-2 text-gray-400 ml-4">
                <li><strong class="text-gray-200">One-time purchases</strong> are a single payment with no renewal. They are eligible for a full refund when requested within 14 days of payment.</li>
                <li><strong class="text-gray-200">Monthly subscriptions</strong> renew automatically each month until you cancel. Your first payment is eligible for a full refund when requested within 14 days of that payment. Later monthly renewals are not refunded, so please cancel before your renewal date if you do not wish to continue.</li>
                <li><strong class="text-gray-200">Cancelling a subscription</strong> is done at any time from your <a href="{{ route('billing.index') }}" class="text-brand-400 hover:text-brand-300">billing page</a>. Cancellation stops future charges, and your plan stays active until the end of the period you have already paid for.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">How to Request a Refund</h2>
            <p>Email <a href="mailto:support@exospace.gallery" class="text-brand-400 hover:text-brand-300">support@exospace.gallery</a> with the subject line <em>"Refund Request"</em> and include:</p>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>The email address or account name used for the purchase.</li>
                <li>The order number or transaction ID (found in your confirmation email or your billing page).</li>
                <li>A brief reason (optional, but it helps us improve).</li>
            </ul>
            <p class="mt-3">We aim to review and process every request within <strong class="text-gray-100">5–7 business days</strong>. Approved refunds go back to the original payment method.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">After a Refund</h2>
            <p>Payments are processed by <strong class="text-gray-100">2Checkout (a Verifone company)</strong>. Once we issue a refund, it normally appears on your statement within 5–10 business days, depending on your bank or card issuer. After a full refund, your account returns to the Free plan and its limits.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Exceptions</h2>
            <p>Refunds will not be issued in the following cases:</p>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>The refund request is made after the 14-day window has passed.</li>
                <li>The account has been used in violation of our <a href="/terms" class="text-brand-400 hover:text-brand-300">Terms of Service</a>.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Problem With a Charge?</h2>
            <p>If you see a charge you do not recognise, or something went wrong during checkout, please contact us first. We can usually resolve it faster than a bank dispute, and we will always respond to billing questions.</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-gray-100 mb-3">Contact Us</h2>
            <ul class="list-disc list-inside mt-3 space-y-1 text-gray-400 ml-4">
                <li>Email: <a href="mailto:support@exospace.gallery" class="text-brand-400 hover:text-brand-300">support@exospace.gallery</a></li>
                @if ($supportPhone = config('app.support_phone'))
                    <li>Phone: {{ $supportPhone }}</li>
                @endif
                @if ($businessAddress = config('app.business_address'))
                    <li>Address: {{ str_replace("\n", ', ', $businessAddress) }}</li>
                @endif
            </ul>
        </section>
    </div>
</main>

@endsection

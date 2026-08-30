<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; padding: 20px; margin: 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .logo { text-align: center; margin-bottom: 30px; font-size: 28px; font-weight: bold; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        h2 { color: #1f2937; margin-bottom: 20px; }
        p { color: #4b5563; line-height: 1.6; margin-bottom: 15px; }
        .plan-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
        .plan-name { font-size: 1.5rem; font-weight: 700; color: #7c3aed; }
        .plan-price { font-size: 1.25rem; color: #4b5563; }
        .btn { display: block; text-align: center; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white !important; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 1rem; margin: 20px 0; }
        .features { background: #f9fafb; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .features ul { margin: 10px 0; padding-left: 20px; }
        .features li { color: #4b5563; margin: 8px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
        .unsubscribe { margin-top: 12px; font-size: 12px; color: #9ca3af; }
        .unsubscribe a { color: #6b7280; text-decoration: underline; }
        .address { margin-top: 8px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>

        <h2>You're almost there, {{ $user->name }}!</h2>

        <p>You started upgrading to <strong>{{ ucfirst($pendingUpgrade->plan) }}</strong> but didn't finish checkout. No worries — your upgrade is still waiting.</p>

        @php
            // ITERATION-5 (billing truth): a pending upgrade can be a
            // RECURRING (subscription) checkout — BillingController selects
            // the recurring 2Checkout product when ?recurring=1 and stores
            // its product_id on the pending row. The old copy hardcoded
            // "$99/$29 one-time — Lifetime access — no subscription",
            // which was wrong for abandoned subscription checkouts.
            $recurringProductId = config('services.2checkout.recurring_product_id_' . $pendingUpgrade->plan);
            $isRecurringCheckout = $recurringProductId && (string) $pendingUpgrade->product_id === (string) $recurringProductId;
        @endphp
        <div class="plan-box">
            <div class="plan-name">{{ ucfirst($pendingUpgrade->plan) }} Plan</div>
            <div class="plan-price">
                @if($isRecurringCheckout)
                    ${{ config('services.2checkout.recurring_price_' . $pendingUpgrade->plan . '_monthly', $pendingUpgrade->plan === 'studio' ? '14.99' : '4.99') }}/month
                @else
                    {{ $pendingUpgrade->plan === 'studio' ? '$99 one-time' : '$29 one-time' }}
                @endif
            </div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 4px;">
                @if($isRecurringCheckout)
                    Monthly subscription — cancel anytime
                @else
                    Lifetime access — one-time purchase
                @endif
            </div>
        </div>

        @if($pendingUpgrade->plan === 'pro')
        <div class="features">
            <strong style="color: #1f2937;">What you'll unlock:</strong>
            <ul>
                <li>5 galleries · 100 images total</li>
                <li>7 venues including Industrial Loft & Dark Museum</li>
                <li>Background music & exhibition scheduling</li>
                <li>No Exospace watermark</li>
            </ul>
        </div>
        @elseif($pendingUpgrade->plan === 'studio')
        <div class="features">
            <strong style="color: #1f2937;">What you'll unlock:</strong>
            <ul>
                <li>Unlimited galleries · 500 images each</li>
                <li>All 11 venues including custom domain support</li>
                <li>White-label branding & custom curtain logo</li>
                <li>Advanced analytics · team collaboration</li>
            </ul>
        </div>
        @endif

        <a href="{{ route('billing.upgrade', $pendingUpgrade->plan) }}{{ $isRecurringCheckout ? '?recurring=1' : '' }}" class="btn">
            Complete Your Upgrade →
        </a>

        <p style="font-size: 13px; color: #9ca3af; text-align: center;">
            Click the button above to start a new checkout with 2Checkout.<br>
            Your upgrade activates automatically after payment.
        </p>

        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            <a href="{{ config('app.url') }}/billing" style="color: #667eea; text-decoration: none;">Manage your billing</a> ·
            <a href="{{ config('app.url') }}/refund-policy" style="color: #667eea; text-decoration: none;">Refund policy</a>

            <div class="unsubscribe">
                You're receiving this email because you started an upgrade on Exospace.<br>
                <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('unsubscribe.show', ['user' => $user->id]) }}">Unsubscribe from marketing emails</a>
            </div>

            <div class="address">
                @if(config('app.business_address'))
                    {{ config('app.business_address') }}
                @else
                    Exospace Gallery
                @endif
            </div>
        </div>
    </div>
</body>
</html>

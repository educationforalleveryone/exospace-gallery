EXOSPACE

EMAIL {{ $step }} OF 3

@if($step === 1)
Your payment didn't go through

Your recent payment for the {{ ucfirst($user->plan) }} plan failed.
This usually happens when a card expires or has insufficient funds.

Hi {{ $user->name }},

We tried to process your {{ ucfirst($user->plan) }} subscription payment, but it was declined. Don't worry — your subscription is still active, and 2Checkout will automatically retry the payment.

To avoid any interruption to your service, please update your payment method:

{{ config('app.url') }}/billing

If your card was recently updated, no action is needed — the retry should succeed automatically.

@elseif($step === 2)
Your payment is still failing

Your {{ ucfirst($user->plan) }} subscription payment has failed again.
Please update your payment method to avoid losing access.

Hi {{ $user->name }},

This is a reminder that your subscription payment is still declining. 2Checkout has retried the charge, but it's still not going through.

If you don't update your payment method soon, your subscription will be cancelled and your account will be downgraded to the Free plan.

Update your payment method now:
{{ config('app.url') }}/billing

You'll keep access until the end of your current billing period. After that, your galleries will remain but only your first gallery will be publicly visible.

@else
Final notice: Subscription cancellation

This is your final notice. Your subscription will be cancelled.
Update your payment method immediately to keep your {{ ucfirst($user->plan) }} features.

Hi {{ $user->name }},

Your {{ ucfirst($user->plan) }} subscription payment has failed for the third time. This is the final email before cancellation.

2Checkout will make one final retry attempt. If it fails, your subscription will be cancelled and your account downgraded to Free.

Update your payment method immediately:
{{ config('app.url') }}/billing

If you no longer wish to subscribe, you can ignore this email — your account will automatically revert to the Free plan at the end of your billing period.
@endif

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
@if(config('app.business_address'))
{{ config('app.business_address') }}
@endif
Manage your billing: {{ config('app.url') }}/billing
Refund policy: {{ config('app.url') }}/refund-policy

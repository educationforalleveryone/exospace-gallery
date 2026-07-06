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
        .alert { border-radius: 6px; padding: 16px; margin: 20px 0; }
        .alert-warning { background: #fef3c7; border: 1px solid #fcd34d; }
        .alert-warning strong { color: #92400e; }
        .alert-danger { background: #fee2e2; border: 1px solid #fca5a5; }
        .alert-danger strong { color: #991b1b; }
        .btn { display: block; text-align: center; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white !important; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 1rem; margin: 20px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
        .address { margin-top: 8px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
        .step-indicator { text-align: center; font-size: 12px; color: #9ca3af; margin-bottom: 20px; letter-spacing: 0.05em; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>
        <div class="step-indicator">EMAIL {{ $step }} OF 3</div>

        @if($step === 1)
        <h2>Your payment didn't go through</h2>
        <div class="alert alert-warning">
            <strong>Your recent payment for the {{ ucfirst($user->plan) }} plan failed.</strong><br>
            This usually happens when a card expires or has insufficient funds.
        </div>
        <p>Hi {{ $user->name }},</p>
        <p>We tried to process your {{ ucfirst($user->plan) }} subscription payment, but it was declined. Don't worry — your subscription is still active, and 2Checkout will automatically retry the payment.</p>
        <p>To avoid any interruption to your service, please update your payment method:</p>
        <a href="{{ config('app.url') }}/billing" class="btn">Update Payment Method →</a>
        <p style="font-size: 13px; color: #6b7280;">If your card was recently updated, no action is needed — the retry should succeed automatically.</p>

        @elseif($step === 2)
        <h2>Your payment is still failing</h2>
        <div class="alert alert-warning">
            <strong>Your {{ ucfirst($user->plan) }} subscription payment has failed again.</strong><br>
            Please update your payment method to avoid losing access.
        </div>
        <p>Hi {{ $user->name }},</p>
        <p>This is a reminder that your subscription payment is still declining. 2Checkout has retried the charge, but it's still not going through.</p>
        <p><strong>If you don't update your payment method soon, your subscription will be cancelled and your account will be downgraded to the Free plan.</strong></p>
        <a href="{{ config('app.url') }}/billing" class="btn">Update Payment Method Now →</a>
        <p style="font-size: 13px; color: #6b7280;">You'll keep access until the end of your current billing period. After that, your galleries will remain but only your first gallery will be publicly visible.</p>

        @else
        <h2>Final notice: Subscription cancellation</h2>
        <div class="alert alert-danger">
            <strong>This is your final notice. Your subscription will be cancelled.</strong><br>
            Update your payment method immediately to keep your {{ ucfirst($user->plan) }} features.
        </div>
        <p>Hi {{ $user->name }},</p>
        <p>Your {{ ucfirst($user->plan) }} subscription payment has failed for the third time. <strong>This is the final email before cancellation.</strong></p>
        <p>2Checkout will make one final retry attempt. If it fails, your subscription will be cancelled and your account downgraded to Free.</p>
        <a href="{{ config('app.url') }}/billing" class="btn">Update Payment Method Immediately →</a>
        <p style="font-size: 13px; color: #6b7280;">If you no longer wish to subscribe, you can ignore this email — your account will automatically revert to the Free plan at the end of your billing period.</p>
        @endif

        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            <a href="{{ config('app.url') }}/billing" style="color: #667eea; text-decoration: none;">Manage your billing</a> ·
            <a href="{{ config('app.url') }}/refund-policy" style="color: #667eea; text-decoration: none;">Refund policy</a>

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

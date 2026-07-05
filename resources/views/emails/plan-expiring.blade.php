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
        .alert { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; padding: 16px; margin: 20px 0; }
        .alert strong { color: #92400e; }
        .btn { display: block; text-align: center; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white !important; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 1rem; margin: 20px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
        .address { margin-top: 8px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>
        <h2>Your {{ ucfirst($user->plan) }} plan expires soon</h2>
        <p>Hi {{ $user->name }},</p>
        <div class="alert">
            <strong>Your {{ ucfirst($user->plan) }} plan expires on {{ $user->plan_expires_at->format('M j, Y') }}.</strong><br>
            That's in {{ now()->diffInDays($user->plan_expires_at) }} days.
        </div>
        <p>After your plan expires, your account will be downgraded to the Free plan (1 gallery, 10 images). Your existing galleries will remain in your account, but only your first gallery will be publicly accessible. Custom domains, logos, and audio will be removed from your galleries.</p>
        <p>To keep all your {{ ucfirst($user->plan) }} features ({{ $user->plan === 'studio' ? 'unlimited galleries, custom domains, white-label' : '5 galleries, background music, no watermark' }}), renew your plan:</p>
        {{-- CONV-5 FIX: The CTA now deep-links directly to the upgrade flow
             for the user's CURRENT plan, skipping the billing portal landing
             page. The user has already paid for this plan once — they want
             to renew it, not browse other options. One click → 2Checkout. --}}
        <a href="{{ config('app.url') }}/billing/upgrade/{{ $user->plan }}" class="btn">Renew {{ ucfirst($user->plan) }} Now →</a>
        <p style="font-size: 13px; color: #6b7280; margin-top: 12px;">Or <a href="{{ config('app.url') }}/billing" style="color: #667eea;">view all billing options</a></p>
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

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
        .btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white !important; padding: 14px 28px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600; margin: 20px 0; }
        .features { background: #f9fafb; padding: 20px; border-radius: 6px; margin: 25px 0; }
        .features ul { margin: 10px 0; padding-left: 20px; }
        .features li { color: #4b5563; margin: 8px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; line-height: 1.5; }
        .footer a { color: #667eea; text-decoration: none; }
        .receipt { background: #f9fafb; padding: 15px 20px; border-radius: 6px; margin: 20px 0; font-family: monospace; font-size: 13px; color: #4b5563; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>

        <h2>Your {{ ucfirst($plan) }} plan is active!</h2>

        <p>Hi {{ $user->name }},</p>

        <p>Your Exospace {{ ucfirst($plan) }} plan is now active. Thank you for your purchase!</p>

        @if($invoiceId)
        <div class="receipt">
            <strong>Receipt</strong><br>
            Plan: {{ ucfirst($plan) }}<br>
            Invoice ID: {{ $invoiceId }}<br>
            Status: Completed<br>
            {{-- ITERATION-5 (billing truth): this email fires for BOTH one-time
                 purchases and monthly subscriptions (the Mailable receives no
                 billing-type flag), so the receipt can no longer hardcode
                 "Lifetime (one-time purchase)" — subscribers were receiving a
                 false receipt. Neutral wording matches the invoice PDF's
                 generic fallback; the billing portal shows the exact type. --}}
            Type: {{ ucfirst($plan) }} plan purchase
        </div>
        <p style="font-size: 13px; color: #9ca3af;">Keep this invoice ID for your records. You can also view it anytime in your <a href="{{ config('app.url') }}/billing" style="color: #667eea;">billing portal</a>.</p>
        @endif

        <div class="features">
            <strong style="color: #1f2937;">What's unlocked with {{ ucfirst($plan) }}:</strong>
            <ul>
                @if($plan === 'pro')
                <li>5 galleries · 100 images total</li>
                <li>All 7 standard venues</li>
                <li>Background music & exhibition scheduling</li>
                <li>No Exospace watermark</li>
                @elseif($plan === 'studio')
                <li>Unlimited galleries · 500 images each</li>
                <li>All 12 venues including Penthouse, Cyber Gallery, Sculpture Garden</li>
                <li>Custom domain (yourname.com) — <a href="{{ config('app.url') }}/admin/galleries" style="color: #667eea;">set up in your gallery settings</a></li>
                <li>White-label branding & custom curtain logo</li>
                @endif
            </ul>
        </div>

        <div style="text-align: center;">
            <a href="{{ config('app.url') }}/admin/galleries" class="btn">Create a New Gallery</a>
        </div>

        <p style="margin-top: 30px;">
            <strong>Questions?</strong> Reply to this email or visit our
            <a href="{{ config('app.url') }}/contact" style="color: #667eea; text-decoration: none;">Help Center</a>.
        </p>

        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            <a href="{{ config('app.url') }}/billing">Manage your billing</a> ·
            <a href="{{ config('app.url') }}/refund-policy">Refund policy</a>
        </div>
    </div>
</body>
</html>

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
        .btn { display: block; text-align: center; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white !important; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 1rem; margin: 20px 0; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
        .unsubscribe { margin-top: 12px; font-size: 12px; color: #9ca3af; }
        .unsubscribe a { color: #6b7280; text-decoration: underline; }
        .address { margin-top: 8px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>
        <h2>Ready to create your first exhibition?</h2>
        <p>Hi {{ $user->name }},</p>
        <p>You joined Exospace a while ago but haven't published a gallery yet. Creating your first 3D exhibition takes just a few minutes:</p>
        <ol style="color: #4b5563; line-height: 2; margin-bottom: 20px;">
            <li>Upload your images</li>
            <li>Pick a venue (White Cube, Industrial Loft, Dark Museum, and more)</li>
            <li>Toggle "Active" to publish</li>
            <li>Share the link with your audience</li>
        </ol>
        <p>That's it — no coding required. Your visitors get an immersive, walkable 3D gallery that works on any device.</p>
        <a href="{{ config('app.url') }}/admin/galleries/create" class="btn">Create Your First Gallery →</a>
        <p style="font-size: 13px; color: #9ca3af; text-align: center;">
            Need help? Reply to this email or visit our
            <a href="{{ config('app.url') }}/contact" style="color: #667eea;">Help Center</a>.
        </p>
        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            <a href="{{ config('app.url') }}/billing" style="color: #667eea; text-decoration: none;">Manage your billing</a>

            <div class="unsubscribe">
                You're receiving this email because you signed up for Exospace and opted in to product tips.<br>
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

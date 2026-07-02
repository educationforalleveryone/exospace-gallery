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
        .steps { background: #f9fafb; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .steps ol { margin: 10px 0; padding-left: 20px; color: #4b5563; line-height: 2; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>
        <h2>Your gallery "{{ $gallery->title }}" is ready!</h2>
        <p>Great work, {{ $user->name }}! You've created your first 3D gallery on Exospace. Now let's add some artwork and make it live.</p>
        <div class="steps">
            <strong style="color: #1f2937;">Next steps:</strong>
            <ol>
                <li>Upload your images — JPEG, PNG, or WebP up to 10MB each</li>
                <li>Pick a venue (White Cube, Industrial Loft, Dark Museum, and more)</li>
                <li>Toggle "Active" to publish your gallery</li>
                <li>Share the link with your audience</li>
            </ol>
        </div>
        <a href="{{ config('app.url') }}/admin/galleries/{{ $gallery->id }}/edit" class="btn">Upload Your Artwork →</a>
        <p style="font-size: 13px; color: #9ca3af; text-align: center;">
            Need help? Reply to this email or visit our
            <a href="{{ config('app.url') }}/contact" style="color: #667eea;">Help Center</a>.
        </p>
        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            <a href="{{ config('app.url') }}/billing" style="color: #667eea; text-decoration: none;">Manage your billing</a>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6; 
            padding: 20px; 
            margin: 0;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #ffffff; 
            padding: 40px 30px; 
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        h2 {
            color: #1f2937;
            margin-bottom: 20px;
        }
        p {
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .btn { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white !important; 
            padding: 14px 28px; 
            text-decoration: none; 
            border-radius: 6px; 
            display: inline-block; 
            font-weight: 600;
            margin: 20px 0;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .features {
            background: #f9fafb;
            padding: 20px;
            border-radius: 6px;
            margin: 25px 0;
        }
        .features ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .features li {
            color: #4b5563;
            margin: 8px 0;
        }
        .footer { 
            margin-top: 40px; 
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px; 
            color: #6b7280; 
            text-align: center; 
            line-height: 1.5;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">EXOSPACE</div>
        
        <h2>Welcome to Exospace, {{ $user->name }}!</h2>
        
        <p>
            We're thrilled to have you on board. You are now part of a community of artists and curators 
            transforming how the world experiences digital art in immersive 3D galleries.
        </p>

        <div class="features">
            <strong style="color: #1f2937;">Your Free Plan includes:</strong>
            <ul>
                <li>Create up to {{ $user->max_galleries ?? 3 }} stunning 3D galleries</li>
                <li>Upload up to {{ $user->max_images ?? 10 }} images per gallery</li>
                <li>Beautiful, professional gallery templates</li>
                <li>Share your art with the world</li>
            </ul>
        </div>

        <div style="text-align: center;">
            <a href="{{ config('app.url') }}/dashboard" class="btn">Create My First Gallery</a>
        </div>

        <p style="margin-top: 30px;">
            <strong>What's next?</strong><br>
            Head to your dashboard and start building your first immersive gallery. 
            It takes just a few minutes to create something spectacular.
        </p>

        <p style="font-size: 14px; color: #6b7280;">
            Need help getting started? Reply to this email or visit our 
            <a href="{{ config('app.url') }}/contact" style="color: #667eea; text-decoration: none;">Help Center</a>.
        </p>

        <div class="footer">
            &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
            Building the future of digital art exhibitions.<br>
            <a href="{{ config('app.url') }}">exospace.gallery</a>
        </div>
    </div>
</body>
</html>
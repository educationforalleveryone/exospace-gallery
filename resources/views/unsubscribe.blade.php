<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Unsubscribe — Exospace</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1117;
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: #0f1117;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 40px;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 24px;
        }
        h1 { font-size: 20px; margin-bottom: 12px; color: #f3f4f6; }
        p { font-size: 14px; line-height: 1.6; color: #9ca3af; margin-bottom: 20px; }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
        .btn:hover { opacity: 0.9; }
        .btn-secondary {
            display: inline-block;
            margin-top: 12px;
            color: #6b7280;
            text-decoration: none;
            font-size: 13px;
        }
        .info { margin-top: 24px; padding-top: 20px; border-top: 1px solid #1f2937; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">EXOSPACE</div>

        @if($already)
            <h1>You're already unsubscribed</h1>
            <p>You won't receive marketing emails from Exospace. Transactional emails (purchase confirmations, plan-expiry reminders) will still be sent as they are essential to your account.</p>
            <a href="{{ config('app.url') }}" class="btn-secondary">Back to Exospace</a>
        @else
            <h1>Unsubscribe from marketing emails?</h1>
            <p>You'll stop receiving abandoned-cart reminders and product tips. You can re-subscribe anytime from your profile settings.</p>
            <p style="font-size: 12px; color: #6b7280;">Account: {{ $user->email }}</p>

            {{-- P0-3 AUDIT FIX: The form action MUST include the signature query
                 parameter so the POST route's `signed` middleware can verify it.
                 Without this, the POST would be rejected as an unsigned URL. --}}
            <form method="POST" action="{{ URL::signedRoute('unsubscribe.confirm', ['user' => $user->id]) }}">
                @csrf
                <button type="submit" class="btn">Yes, unsubscribe me</button>
            </form>
            <a href="{{ config('app.url') }}" class="btn-secondary">No, keep me subscribed</a>
        @endif

        <div class="info">
            &copy; {{ date('Y') }} Exospace Gallery<br>
            @if(config('app.business_address'))
                {{ config('app.business_address') }}
            @else
                <span style="color: #a3514a;">Business address not configured — set EXOSPACE_BUSINESS_ADDRESS in .env</span>
            @endif
        </div>
    </div>
</body>
</html>

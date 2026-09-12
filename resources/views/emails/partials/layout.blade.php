<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title', 'Exospace Gallery')</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .email-body { background-color: #1a1a2e !important; }
            .email-card { background-color: #16213e !important; }
            .email-text { color: #e0e0e0 !important; }
            .email-muted { color: #a0a0a0 !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">

    @yield('preheader')

    {{-- Wrapper table — centers the email on desktop --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:20px;">

                {{-- Main email card --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" class="email-card" style="background-color:#ffffff;border-radius:8px;max-width:600px;width:100%;">

                    {{-- Logo header --}}
                    <tr>
                        <td align="center" style="padding:30px 30px 20px 30px;">
                            <span style="font-size:28px;font-weight:bold;color:#667eea;letter-spacing:2px;">EXOSPACE</span>
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td class="email-body" style="padding:0 30px 30px 30px;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="email-card" style="padding:20px 30px;border-top:1px solid #e5e7eb;background-color:#fafafa;border-radius:0 0 8px 8px;">
                            <p class="email-muted" style="font-size:13px;color:#6b7280;text-align:center;line-height:1.5;margin:0;">
                                &copy; {{ date('Y') }} Exospace Gallery. All rights reserved.<br>
                                @if(config('app.business_address'))
                                    {{ config('app.business_address') }}<br>
                                @endif
                                <a href="{{ config('app.url') }}" style="color:#667eea;text-decoration:none;">exospace.gallery</a>
                                @if(isset($unsubscribeUrl))
                                    &nbsp;|&nbsp; <a href="{{ $unsubscribeUrl }}" style="color:#667eea;text-decoration:none;">Unsubscribe</a>
                                @endif
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>

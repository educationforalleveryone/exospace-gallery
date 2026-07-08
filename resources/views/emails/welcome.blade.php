{{-- B-9 + B-10 FIX (Iter-004): Welcome email — now uses shared table-based layout
    with inline CSS (Outlook/Gmail compatible) + preheader text (5-15% open rate lift). --}}
@extends('emails.partials.layout')

@section('title', 'Welcome to Exospace')

@section('preheader')
    {{-- B-10 FIX: Preheader text — hidden but visible in inbox preview. Max 85 chars. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
        Your 3D gallery awaits — create your first exhibition in 5 minutes.
    </div>
@endsection

@section('content')
    <h2 class="email-text" style="color:#1f2937;font-size:24px;margin:0 0 20px 0;">Welcome to Exospace, {{ $user->name }}!</h2>

    <p class="email-text" style="color:#4b5563;line-height:1.6;margin:0 0 15px 0;">
        We're thrilled to have you on board. You are now part of a community of artists and curators
        transforming how the world experiences digital art in immersive 3D galleries.
    </p>

    {{-- Features box --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:6px;margin:25px 0;">
        <tr>
            <td style="padding:20px;">
                <p class="email-text" style="color:#1f2937;font-weight:bold;margin:0 0 10px 0;">What you can do with Exospace:</p>
                <ul class="email-text" style="color:#4b5563;margin:10px 0;padding-left:20px;line-height:1.8;">
                    <li>Build immersive 3D galleries in minutes</li>
                    <li>Share your art with a single link</li>
                    <li>Beautiful templates, custom lighting &amp; music</li>
                    <li>Real-time visitor analytics</li>
                </ul>
            </td>
        </tr>
    </table>

    {{-- CTA button — table-based for Outlook --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding:20px 0;">
                <a href="{{ config('app.url') }}/admin/dashboard"
                   style="background-color:#667eea;color:#ffffff;padding:14px 28px;text-decoration:none;border-radius:6px;font-weight:600;display:inline-block;">
                    Go to My Dashboard
                </a>
            </td>
        </tr>
    </table>

    <p class="email-muted" style="font-size:13px;color:#9ca3af;text-align:center;margin:0 0 20px 0;">
        Check your inbox for a separate email to verify your address.
    </p>

    <p class="email-text" style="color:#4b5563;margin:20px 0;">
        <strong>What's next?</strong><br>
        Head to your dashboard and start building your first immersive gallery.
        It takes just a few minutes to create something spectacular.
    </p>

    <p class="email-muted" style="font-size:14px;color:#6b7280;margin:0;">
        Need help getting started? Reply to this email or visit our
        <a href="{{ config('app.url') }}/contact" style="color:#667eea;text-decoration:none;">Help Center</a>.
    </p>
@endsection

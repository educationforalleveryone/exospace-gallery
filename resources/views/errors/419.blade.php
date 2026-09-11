<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- LOGOUT-ITERATION FIX: branded 419 page. Previously a stale,
         expired, or double-submitted logout (or any other CSRF-guarded
         form submitted with a dead session) hit Laravel's default
         "419 | Page Expired" screen — a bare white page with no links, a
         confusing dead end on mobile. This view renders the same HTTP
         semantics in the product's own visual language and always offers
         a way forward (home / log in).

         Deliberately self-contained: inline CSS only, no Vite bundle, no
         external fonts — error pages must render even when the app
         bundle, CDN, or session that would provide them is unavailable. --}}
    <meta name="robots" content="noindex">
    <title>Page Expired — {{ config('app.name', 'Exospace') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #0f1117;
            color: #e5e7eb;
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            text-align: center;
        }
        .panel { max-width: 26rem; }
        .code {
            font-size: 4.5rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.02em;
            color: #a78bfa;
            margin: 0 0 .75rem;
        }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 .5rem; }
        p { color: #9ca3af; font-size: .925rem; line-height: 1.6; margin: 0 0 1.75rem; }
        .actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 2.75rem; padding: 0 1.25rem;
            border-radius: .5rem; font-size: .9rem; font-weight: 600;
            text-decoration: none; transition: background-color .15s ease, border-color .15s ease;
        }
        .btn-primary { background: #7c3aed; color: #ffffff; }
        .btn-primary:hover { background: #8b5cf6; }
        .btn-ghost { border: 1px solid #374151; color: #d1d5db; background: transparent; }
        .btn-ghost:hover { border-color: #4b5563; background: rgba(255,255,255,.04); }
    </style>
</head>
<body>
    <main class="panel">
        <p class="code">419</p>
        <h1>{{ __('Page Expired') }}</h1>
        <p>
            {{ __('Your session timed out or the page was opened too long ago. For your security the request was not processed.') }}
        </p>
        <div class="actions">
            <a href="{{ url('/') }}" class="btn btn-ghost">{{ __('Back to home') }}</a>
            <a href="{{ route('login') }}" class="btn btn-primary">{{ __('Log in') }}</a>
        </div>
    </main>
</body>
</html>

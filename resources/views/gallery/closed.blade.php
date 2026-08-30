<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $gallery->title }} — Exhibition Closed</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: #0a0a0f;
            color: #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            text-align: center;
            max-width: 520px;
            width: 100%;
        }
        .logo {
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.3em;
            color: #4b5563;
            margin-bottom: 3rem;
            text-transform: uppercase;
            text-decoration: none;
            display: block;
        }
        .icon {
            width: 72px; height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #374151, #1f2937);
            border: 1px solid #374151;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.75rem;
            font-size: 2rem;
        }
        h1 {
            font-size: clamp(1.5rem, 4vw, 2.25rem);
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }
        .subtitle {
            font-size: 0.95rem;
            color: #64748b;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .meta {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            align-items: center;
            margin-bottom: 2.5rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(107,114,128,0.1);
            border: 1px solid rgba(107,114,128,0.2);
            border-radius: 999px;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .stats {
            display: flex;
            justify-content: center;
            gap: 2.5rem;
            padding: 1.5rem;
            background: rgba(255,255,255,0.02);
            border: 1px solid #1e2030;
            border-radius: 12px;
            margin-bottom: 2.5rem;
        }
        .stat-num {
            font-size: 1.75rem;
            font-weight: 800;
            color: #f1f5f9;
            display: block;
        }
        .stat-label {
            font-size: 0.75rem; /* 12px floor (ITERATION-7; was 0.7rem) */
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #4b5563;
            margin-top: 0.2rem;
        }
        .divider {
            width: 40px; height: 1px;
            background: #1e2030;
            margin: 2rem auto;
        }
        .cta {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            text-decoration: none;
            padding: 0.75rem 1.75rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            transition: opacity 0.2s;
        }
        .cta:hover { opacity: 0.85; }
        .back {
            display: block;
            font-size: 0.82rem;
            color: #4b5563;
            text-decoration: none;
            margin-top: 1rem;
            transition: color 0.2s;
        }
        .back:hover { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <a href="/" class="logo">Exospace</a>

        <div class="icon">🏛️</div>

        <h1>{{ $gallery->title }}</h1>
        <p class="subtitle">
            This exhibition has concluded. Thank you to everyone who visited.
        </p>

        <div class="meta">
            @if($gallery->closes_at)
                <span class="badge">
                    🔒 Closed {{ \Carbon\Carbon::parse($gallery->closes_at)->format('F j, Y') }}
                </span>
            @endif
            @if($gallery->opens_at)
                <span class="badge">
                    🗓 Ran from {{ \Carbon\Carbon::parse($gallery->opens_at)->format('F j') }}
                    to {{ \Carbon\Carbon::parse($gallery->closes_at)->format('F j, Y') }}
                </span>
            @endif
        </div>

        <div class="stats">
            <div>
                <span class="stat-num">{{ number_format($gallery->view_count) }}</span>
                <span class="stat-label">Total Visitors</span>
            </div>
            <div>
                <span class="stat-num">{{ $gallery->images()->count() }}</span>
                <span class="stat-label">Artworks</span>
            </div>
            @if($gallery->closes_at && $gallery->opens_at)
            <div>
                <span class="stat-num">
                    {{ \Carbon\Carbon::parse($gallery->opens_at)->diffInDays($gallery->closes_at) }}
                </span>
                <span class="stat-label">Days Open</span>
            </div>
            @endif
        </div>

        <a href="{{ route('register') }}" class="cta">Create Your Own Gallery →</a>

        <div class="divider"></div>

        <a href="/" class="back">← Back to Exospace</a>
    </div>
</body>
</html>
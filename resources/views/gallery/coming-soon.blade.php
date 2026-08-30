<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $gallery->title }} — Opening Soon</title>
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
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
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
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }
        .countdown-wrap {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .countdown-unit {
            text-align: center;
        }
        .countdown-num {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            display: block;
            font-variant-numeric: tabular-nums;
            min-width: 2.5ch;
        }
        .countdown-label {
            font-size: 0.75rem; /* 12px floor (ITERATION-7; was 0.65rem) */
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #4b5563;
            margin-top: 0.35rem;
        }
        .opens-date {
            display: inline-block;
            background: rgba(59,130,246,0.08);
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 999px;
            padding: 0.5rem 1.25rem;
            font-size: 0.82rem;
            color: #60a5fa;
            margin-bottom: 2.5rem;
        }
        .back {
            font-size: 0.82rem;
            color: #4b5563;
            text-decoration: none;
            transition: color 0.2s;
        }
        .back:hover { color: #94a3b8; }
        .divider {
            width: 40px; height: 1px;
            background: #1e2030;
            margin: 2.5rem auto;
        }
    </style>
</head>
<body>
    <div class="card">
        <a href="/" class="logo">Exospace</a>

        <div class="icon">🎭</div>

        <h1>{{ $gallery->title }}</h1>
        <p class="subtitle">
            {{ $gallery->description ?: 'This exhibition is not yet open to the public. Check back on the opening date.' }}
        </p>

        <div class="countdown-wrap" id="countdown">
            <div class="countdown-unit">
                <span class="countdown-num" id="cd-days">--</span>
                <span class="countdown-label">Days</span>
            </div>
            <div class="countdown-unit">
                <span class="countdown-num" id="cd-hours">--</span>
                <span class="countdown-label">Hours</span>
            </div>
            <div class="countdown-unit">
                <span class="countdown-num" id="cd-mins">--</span>
                <span class="countdown-label">Minutes</span>
            </div>
            <div class="countdown-unit">
                <span class="countdown-num" id="cd-secs">--</span>
                <span class="countdown-label">Seconds</span>
            </div>
        </div>

        <div class="opens-date">
            🗓 Opens {{ \Carbon\Carbon::parse($gallery->opens_at)->format('F j, Y \a\t g:i A') }}
        </div>

        <div class="divider"></div>

        <a href="/" class="back">← Back to Exospace</a>
    </div>

    <script nonce="@nonce">
        const opensAt = new Date('{{ \Carbon\Carbon::parse($gallery->opens_at)->toIso8601String() }}').getTime();

        function tick() {
            const diff = opensAt - Date.now();
            if (diff <= 0) { location.reload(); return; }

            const d = Math.floor(diff / 86400000);
            const h = Math.floor((diff % 86400000) / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);

            document.getElementById('cd-days').textContent  = String(d).padStart(2, '0');
            document.getElementById('cd-hours').textContent = String(h).padStart(2, '0');
            document.getElementById('cd-mins').textContent  = String(m).padStart(2, '0');
            document.getElementById('cd-secs').textContent  = String(s).padStart(2, '0');
        }

        tick();
        setInterval(tick, 1000);
    </script>
</body>
</html>
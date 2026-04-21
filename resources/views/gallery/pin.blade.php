<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $gallery->title }} — Private Gallery</title>
    @vite(['resources/css/app.css'])
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #16213e 100%);
            display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
        }
        .card {
            width: 100%; max-width: 400px; padding: 2.5rem;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 20px;
            text-align: center;
        }
        .lock-icon {
            width: 56px; height: 56px; margin: 0 auto 1.5rem;
            background: rgba(139,92,246,0.15);
            border: 1px solid rgba(139,92,246,0.3);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        h1 { font-size: 1.4rem; font-weight: 700; color: white; margin-bottom: 0.4rem; }
        .subtitle { font-size: 0.875rem; color: rgba(255,255,255,0.5); margin-bottom: 2rem; }
        .pin-row {
            display: flex; justify-content: center; gap: 12px; margin-bottom: 1.5rem;
        }
        .pin-digit {
            width: 56px; height: 64px;
            background: rgba(255,255,255,0.06);
            border: 1.5px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            color: white; font-size: 1.6rem; font-weight: 700;
            text-align: center;
            transition: border-color 0.2s, background 0.2s;
            caret-color: transparent;
        }
        .pin-digit:focus {
            outline: none;
            border-color: rgba(139,92,246,0.7);
            background: rgba(139,92,246,0.08);
        }
        .pin-digit.has-value { border-color: rgba(139,92,246,0.5); }
        .error-msg {
            color: #f87171; font-size: 0.8rem; margin-bottom: 1.2rem;
            background: rgba(248,113,113,0.08);
            border: 1px solid rgba(248,113,113,0.2);
            border-radius: 8px; padding: 0.5rem 1rem;
        }
        .submit-btn {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none; border-radius: 10px;
            color: white; font-size: 1rem; font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
        }
        .submit-btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .submit-btn:active { transform: scale(0.98); }
        .hint { font-size: 0.75rem; color: rgba(255,255,255,0.3); margin-top: 1.2rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="lock-icon">
            <svg width="24" height="24" fill="none" stroke="#8b5cf6" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <h1>{{ $gallery->title }}</h1>
        <p class="subtitle">This gallery is private. Enter the 4-digit PIN to continue.</p>

        @if($errors->has('pin'))
            <div class="error-msg">{{ $errors->first('pin') }}</div>
        @endif

        <form method="POST" action="{{ route('gallery.pin.verify', $gallery->slug) }}" id="pin-form">
            @csrf
            <input type="hidden" name="pin" id="pin-hidden">

            <div class="pin-row">
                <input class="pin-digit" type="text" inputmode="numeric" maxlength="1" data-index="0" autocomplete="off">
                <input class="pin-digit" type="text" inputmode="numeric" maxlength="1" data-index="1" autocomplete="off">
                <input class="pin-digit" type="text" inputmode="numeric" maxlength="1" data-index="2" autocomplete="off">
                <input class="pin-digit" type="text" inputmode="numeric" maxlength="1" data-index="3" autocomplete="off">
            </div>

            <button type="submit" class="submit-btn" id="submit-btn" disabled>Enter Gallery</button>
        </form>

        <p class="hint">Contact the gallery owner if you don't have the PIN.</p>
    </div>

    <script>
        const digits  = document.querySelectorAll('.pin-digit');
        const hidden  = document.getElementById('pin-hidden');
        const submit  = document.getElementById('submit-btn');

        digits[0].focus();

        digits.forEach((input, i) => {
            input.addEventListener('input', () => {
                // Keep only last digit typed
                input.value = input.value.replace(/\D/g, '').slice(-1);
                if (input.value) {
                    input.classList.add('has-value');
                    if (i < 3) digits[i + 1].focus();
                } else {
                    input.classList.remove('has-value');
                }
                sync();
            });

            input.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !input.value && i > 0) {
                    digits[i - 1].value = '';
                    digits[i - 1].classList.remove('has-value');
                    digits[i - 1].focus();
                    sync();
                }
            });

            // Handle paste on first digit
            input.addEventListener('paste', e => {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,4);
                text.split('').forEach((ch, j) => {
                    if (digits[j]) {
                        digits[j].value = ch;
                        digits[j].classList.add('has-value');
                    }
                });
                if (digits[text.length - 1]) digits[Math.min(text.length, 3)].focus();
                sync();
            });
        });

        function sync() {
            const pin = Array.from(digits).map(d => d.value).join('');
            hidden.value = pin;
            submit.disabled = pin.length < 4;
        }

        // Auto-submit when all 4 digits filled
        document.getElementById('pin-form').addEventListener('input', () => {
            const pin = Array.from(digits).map(d => d.value).join('');
            if (pin.length === 4) setTimeout(() => document.getElementById('pin-form').submit(), 200);
        });
    </script>
</body>
</html>
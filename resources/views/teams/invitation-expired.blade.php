<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Expired — Exospace</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full text-center">
        {{-- ITERATION-6: retired pre-iteration-1 purple→indigo logo gradient —
             this page was missed by iteration 4's public sweep (only its
             sibling teams/invitation was converted). .logo-text is canonical. --}}
        <a href="/" class="logo-text text-3xl mb-8 inline-block">
            Exospace
        </a>
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-10 shadow-card-hover">
            <div class="text-5xl mb-4">⏰</div>
            <h1 class="page-title text-white mb-3">Invitation Expired</h1>
            <p class="text-gray-400 text-sm mb-2">
                This invitation to join <strong class="text-gray-200">{{ $invitation->team->name }}</strong> has expired.
            </p>
            <p class="text-gray-500 text-sm mb-6">
                Ask <strong class="text-gray-300">{{ $invitation->team->owner->name }}</strong> to send a new invitation to <strong class="text-gray-300">{{ $invitation->email }}</strong>.
            </p>
            <div class="bg-gray-700/40 border border-gray-600/50 rounded-lg px-4 py-3 mb-6 text-left">
                <p class="text-gray-500 text-xs mb-1">You can send them this message:</p>
                <p class="text-gray-300 text-sm">"Hi, my invitation link to {{ $invitation->team->name }} has expired — could you send a new one to {{ $invitation->email }}?"</p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="btn btn-secondary">
                Go to Dashboard
            </a>
        </div>
    </div>
</body>
</html>

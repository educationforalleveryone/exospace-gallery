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
        <a href="/" class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent mb-8 inline-block">
            Exospace
        </a>
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-10 shadow-2xl">
            <div class="text-5xl mb-4">⏰</div>
            <h1 class="text-white text-xl font-bold mb-3">Invitation Expired</h1>
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
            <a href="{{ route('admin.dashboard') }}" class="inline-block py-2.5 px-6 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded-xl font-medium transition text-sm">
                Go to Dashboard
            </a>
        </div>
    </div>
</body>
</html>

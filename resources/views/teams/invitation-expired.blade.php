<!DOCTYPE html>
<html lang="en">
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
            <p class="text-gray-500 text-sm mb-8">
                Ask the team owner to send you a new invitation.
            </p>
            <a href="{{ route('admin.dashboard') }}" class="inline-block py-2.5 px-6 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded-xl font-medium transition text-sm">
                Go to Dashboard
            </a>
        </div>
    </div>
</body>
</html>

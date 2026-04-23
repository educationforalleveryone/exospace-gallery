<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Invitation — Exospace</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full">
        <div class="text-center mb-8">
            <a href="/" class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">
                Exospace
            </a>
        </div>

        <div class="bg-gray-800 border border-gray-700 rounded-2xl overflow-hidden shadow-2xl">
            <div class="h-1.5 bg-gradient-to-r from-purple-600 to-indigo-600"></div>
            <div class="p-8">

                {{-- Team avatar --}}
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white text-2xl font-bold mx-auto mb-6">
                    {{ strtoupper(substr($team->name, 0, 1)) }}
                </div>

                <h1 class="text-white text-xl font-bold text-center mb-2">You're invited!</h1>
                <p class="text-gray-400 text-center text-sm mb-6">
                    <strong class="text-gray-200">{{ $team->owner->name }}</strong> has invited you to join
                    <strong class="text-gray-200">{{ $team->name }}</strong> as
                    <span class="text-purple-400 font-medium capitalize">{{ $invitation->role }}</span>.
                </p>

                @if($team->description)
                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-3 mb-6 text-center">
                    <p class="text-gray-300 text-sm italic">"{{ $team->description }}"</p>
                </div>
                @endif

                {{-- Role explanation --}}
                <div class="bg-gray-700/30 border border-gray-600/50 rounded-lg p-4 mb-6">
                    <p class="text-gray-300 text-sm font-medium mb-1">As an {{ $invitation->role }}, you can:</p>
                    @if($invitation->role === 'editor')
                        <ul class="text-gray-400 text-sm space-y-1">
                            <li class="flex items-center gap-2"><span class="text-green-400">✓</span> Create and manage team galleries</li>
                            <li class="flex items-center gap-2"><span class="text-green-400">✓</span> Upload and edit images</li>
                            <li class="flex items-center gap-2"><span class="text-green-400">✓</span> View analytics</li>
                        </ul>
                    @else
                        <ul class="text-gray-400 text-sm space-y-1">
                            <li class="flex items-center gap-2"><span class="text-green-400">✓</span> View all team galleries</li>
                            <li class="flex items-center gap-2"><span class="text-green-400">✓</span> Access analytics</li>
                            <li class="flex items-center gap-2"><span class="text-red-400">✗</span> Cannot create or edit galleries</li>
                        </ul>
                    @endif
                </div>

                @auth
                    {{-- Logged in: show accept/decline --}}
                    @if(strtolower(auth()->user()->email) !== strtolower($invitation->email))
                        <div class="p-3 bg-yellow-900/40 border border-yellow-700/50 rounded-lg mb-4">
                            <p class="text-yellow-300 text-sm text-center">
                                ⚠️ This invitation was sent to <strong>{{ $invitation->email }}</strong>.<br>
                                You're logged in as <strong>{{ auth()->user()->email }}</strong>.<br>
                                Please log in with the correct account.
                            </p>
                        </div>
                        <a href="{{ route('logout') }}" class="block w-full text-center py-3 bg-gray-700 hover:bg-gray-600 text-gray-200 rounded-xl font-medium transition">
                            Switch Account
                        </a>
                    @else
                        <div class="space-y-3">
                            <form action="{{ url('/team-invitations/' . $token . '/accept') }}" method="POST">
                                @csrf
                                <button type="submit" class="w-full py-3 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold rounded-xl transition">
                                    Accept &amp; Join Team
                                </button>
                            </form>
                            <form action="{{ url('/team-invitations/' . $token . '/decline') }}" method="POST">
                                @csrf
                                <button type="submit" class="w-full py-3 bg-transparent border border-gray-600 hover:border-gray-500 text-gray-400 hover:text-gray-300 font-medium rounded-xl transition text-sm">
                                    Decline Invitation
                                </button>
                            </form>
                        </div>
                    @endif
                @else
                    {{-- Guest: prompt to log in or register --}}
                    <p class="text-gray-400 text-sm text-center mb-4">You'll need an Exospace account to join.</p>
                    <div class="space-y-3">
                        <a href="{{ route('register') }}?invitation={{ $token }}"
                           class="block w-full text-center py-3 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold rounded-xl transition">
                            Create Account &amp; Join
                        </a>
                        <a href="{{ route('login') }}?invitation={{ $token }}"
                           class="block w-full text-center py-3 border border-gray-600 hover:border-gray-500 text-gray-300 font-medium rounded-xl transition text-sm">
                            Log In to Accept
                        </a>
                    </div>
                @endauth

                <p class="text-gray-600 text-xs text-center mt-5">
                    Expires {{ $invitation->expires_at->diffForHumans() }}
                </p>
            </div>
        </div>
    </div>
</body>
</html>

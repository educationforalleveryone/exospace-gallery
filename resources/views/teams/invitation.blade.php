<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Invitation — Exospace</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full">
        <div class="text-center mb-8">
            <a href="/" class="logo-text text-3xl">
                Exospace
            </a>
        </div>

        <div class="bg-gray-800 border border-gray-700 rounded-2xl overflow-hidden shadow-2xl">
            <div class="h-1.5 bg-gradient-to-r from-brand-600 to-brand-400"></div>
            <div class="p-8">

                {{-- Team avatar --}}
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-brand-500 to-brand-800 flex items-center justify-center text-white text-2xl font-bold mx-auto mb-6">
                    {{ strtoupper(substr($team?->name ?? 'E', 0, 1)) }}
                </div>

                <h1 class="text-white text-xl font-bold text-center mb-2">You're invited!</h1>
                {{-- ITERATION-1 P0 FIX (500 on every invitation email link): --}}
                {{-- the controller passes $team = null for visitors who --}}
                {{-- are not the invited recipient (privacy — team name is --}}
                {{-- hidden until the email matches). The view dereferenced --}}
                {{-- $team unconditionally, so the DEFAULT flow — an --}}
                {{-- unauthenticated person clicking the link in the --}}
                {{-- invitation email — crashed with HTTP 500. --}}
                @if($team)
                    <p class="text-gray-400 text-center text-sm mb-6">
                        <strong class="text-gray-200">{{ $team->owner->name }}</strong> has invited you to join
                        <strong class="text-gray-200">{{ $team->name }}</strong> as
                        <span class="text-brand-400 font-medium capitalize">{{ $invitation->role }}</span>.
                    </p>
                @else
                    <p class="text-gray-400 text-center text-sm mb-6">
                        You've been invited to collaborate on Exospace. Sign in with the
                        email that received this invitation to see the details.
                    </p>
                @endif

                @if($team?->description)
                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-3 mb-6 text-center">
                    <p class="text-gray-300 text-sm italic">"{{ $team->description }}"</p>
                </div>
                @endif

                {{-- Role explanation --}}
                <div class="bg-gray-700/30 border border-gray-600/50 rounded-lg p-4 mb-6">
                    <p class="text-gray-300 text-sm font-medium mb-1">As an {{ $invitation->role }}, you can:</p>
                    @if($invitation->role === 'editor')
                        <ul class="text-gray-400 text-sm space-y-1">
                            <li class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Create and manage team galleries</li>
                            <li class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Upload and edit images</li>
                            <li class="flex items-center gap-2"><span class="text-emerald-400">✓</span> View analytics</li>
                        </ul>
                    @else
                        <ul class="text-gray-400 text-sm space-y-1">
                            <li class="flex items-center gap-2"><span class="text-emerald-400">✓</span> View all team galleries</li>
                            <li class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Access analytics</li>
                            <li class="flex items-center gap-2"><span class="text-red-400">✗</span> Cannot create or edit galleries</li>
                        </ul>
                    @endif
                </div>

                @auth
                    {{-- Logged in: show accept/decline --}}
                    @if(strtolower(auth()->user()->email) !== strtolower($invitation->email))
                        <div class="p-3 bg-amber-900/40 border border-amber-700/50 rounded-lg mb-4">
                            <p class="text-amber-300 text-sm text-center">
                                ⚠️ This invitation was sent to <strong>{{ $invitation->email }}</strong>.<br>
                                You're logged in as <strong>{{ auth()->user()->email }}</strong>.<br>
                                Please log in with the correct account.
                            </p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-lg w-full">
                                Switch Account
                            </button>
                        </form>
                    @else
                        <div class="space-y-3">
                            <form action="{{ url('/team-invitations/' . $token . '/accept') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-lg btn-primary w-full">
                                    Accept &amp; Join Team
                                </button>
                            </form>
                            <form action="{{ url('/team-invitations/' . $token . '/decline') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-lg btn-secondary w-full">
                                    Decline Invitation
                                </button>
                            </form>
                        </div>
                    @endif
                @else
                    {{-- Guest: show login or register depending on whether account exists --}}
                    @if($accountExists)
                        {{-- Account already exists — only show login --}}
                        <div class="p-3 bg-blue-900/40 border border-blue-700/50 rounded-lg mb-4">
                            <p class="text-blue-300 text-sm text-center">
                                An account already exists for <strong>{{ $invitation->email }}</strong>.<br>
                                Log in to accept this invitation.
                            </p>
                        </div>
                        <a href="{{ route('login') }}?redirect={{ urlencode(request()->fullUrl()) }}"
                           class="btn btn-lg btn-primary w-full">
                            Log In to Accept
                        </a>
                    @else
                        {{-- No account yet — offer register or login --}}
                        <p class="text-gray-400 text-sm text-center mb-4">You'll need an Exospace account to join.</p>
                        <div class="space-y-3">
                            <a href="{{ route('register') }}?invitation={{ $token }}"
                               class="btn btn-lg btn-primary w-full">
                                Create Account &amp; Join
                            </a>
                            <a href="{{ route('login') }}?redirect={{ urlencode(request()->fullUrl()) }}"
                               class="btn btn-lg btn-secondary w-full">
                                Already have an account? Log In
                            </a>
                        </div>
                    @endif
                @endauth

                <p class="text-gray-600 text-xs text-center mt-5">
                    Expires {{ $invitation->expires_at->diffForHumans() }}
                </p>
            </div>
        </div>
    </div>
</body>
</html>
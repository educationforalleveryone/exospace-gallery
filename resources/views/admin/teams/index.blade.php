<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-100 leading-tight">
                My Teams
            </h2>
            <a href="{{ route('admin.teams.create') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-2 px-6 rounded-lg transition inline-flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Team
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            @if(session('status'))
                <div class="p-4 bg-green-900 border border-green-700 text-green-200 rounded-lg">{{ session('status') }}</div>
            @endif

            {{-- Info callout for free users --}}
            @if(auth()->user()->plan === 'free')
            <div class="mb-6 flex items-start gap-3 bg-blue-950/40 border border-blue-700/40 rounded-xl px-4 py-3">
                <svg class="w-4 h-4 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm text-blue-200">Teams use the <strong>owner's plan</strong>. If the team owner is on Pro, all members get Pro gallery limits. Your personal galleries follow your own plan.</p>
            </div>
            @endif

            {{-- Teams I Own --}}
            <div>
                <h3 class="text-lg font-semibold text-gray-200 mb-4">Teams You Own</h3>
                @if($ownedTeams->isEmpty())
                    <div class="bg-gray-800 border border-gray-700 rounded-lg p-8 text-center">
                        <svg class="w-12 h-12 text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <p class="text-gray-400 mb-4">You haven't created any teams yet.</p>
                        <a href="{{ route('admin.teams.create') }}" class="text-purple-400 hover:text-purple-300 font-medium">Create your first team →</a>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($ownedTeams as $team)
                        <div class="bg-gray-800 border border-gray-700 hover:border-purple-500 rounded-lg p-5 transition group">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <h4 class="text-white font-semibold text-lg group-hover:text-purple-300 transition">{{ $team->name }}</h4>
                                    @if($team->description)
                                        <p class="text-gray-400 text-sm mt-0.5">{{ Str::limit($team->description, 60) }}</p>
                                    @endif
                                </div>
                                <span class="text-xs bg-purple-900/50 text-purple-300 border border-purple-700/50 px-2 py-1 rounded-full">Owner</span>
                            </div>
                            <div class="flex items-center gap-3 text-sm text-gray-400 mb-4">
                                <span class="flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                    {{ $team->members_count }} member{{ $team->members_count !== 1 ? 's' : '' }}
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <a href="{{ route('admin.teams.show', $team) }}" class="flex-1 text-center text-sm bg-gray-700 hover:bg-gray-600 text-gray-200 py-2 px-3 rounded-lg transition">Manage</a>
                                <form action="{{ route('admin.teams.switch', $team) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full text-sm bg-purple-700/30 hover:bg-purple-700/50 text-purple-300 py-2 px-3 rounded-lg transition border border-purple-700/30">
                                        {{ auth()->user()->current_team_id === $team->id ? '✓ Active' : 'Switch to' }}
                                    </button>
                                </form>
                            </div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Teams I'm a Member Of --}}
            @if($memberTeams->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold text-gray-200 mb-4">Teams You're In</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($memberTeams as $team)
                    @php $role = auth()->user()->teamRole($team); @endphp
                    <div class="bg-gray-800 border border-gray-700 hover:border-indigo-500 rounded-lg p-5 transition group">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h4 class="text-white font-semibold text-lg group-hover:text-indigo-300 transition">{{ $team->name }}</h4>
                                <p class="text-gray-400 text-sm">Owned by {{ $team->owner->name }}</p>
                            </div>
                            <span class="text-xs bg-indigo-900/50 text-indigo-300 border border-indigo-700/50 px-2 py-1 rounded-full capitalize">{{ $role }}</span>
                        </div>
                        <div class="flex gap-2 mt-4">
                            <a href="{{ route('admin.teams.show', $team) }}" class="flex-1 text-center text-sm bg-gray-700 hover:bg-gray-600 text-gray-200 py-2 px-3 rounded-lg transition">View</a>
                            <form action="{{ route('admin.teams.switch', $team) }}" method="POST" class="flex-1">
                                @csrf
                                <button type="submit" class="w-full text-sm bg-indigo-700/30 hover:bg-indigo-700/50 text-indigo-300 py-2 px-3 rounded-lg transition border border-indigo-700/30">
                                    {{ auth()->user()->current_team_id === $team->id ? '✓ Active' : 'Switch to' }}
                                </button>
                            </form>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>

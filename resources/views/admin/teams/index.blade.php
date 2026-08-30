<x-app-layout>
    <x-slot name="header">
        @php $currentTeam = auth()->user()->currentTeam(); @endphp
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="page-title">Teams</h1>
                <p class="page-subtitle flex flex-wrap items-center gap-x-1.5">
                    Active workspace:
                    @if($currentTeam)
                        <span class="text-brand-400 font-medium">{{ $currentTeam->name }}</span>
                        <span aria-hidden="true">·</span>
                        <form action="{{ route('admin.teams.switch-personal') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-600 hover:text-gray-400 transition underline underline-offset-2 text-xs">Switch to personal</button>
                        </form>
                    @else
                        <span class="text-gray-400">Personal</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('admin.teams.create') }}" class="btn btn-primary shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Team
            </a>
        </div>
    </x-slot>

    <div class="page-shell space-y-8">

            {{-- Info callout for free users --}}
            @if(auth()->user()->plan === 'free')
            <div class="mb-6 alert alert-info">
                <svg class="w-4 h-4 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm text-blue-200">Teams use the <strong>owner's plan</strong>. If the team owner is on Pro, all members get Pro gallery limits. Your personal galleries follow your own plan.</p>
            </div>
            @endif

            {{-- Teams I Own --}}
            <div>
                <h3 class="text-lg font-semibold text-gray-200 mb-4">Teams You Own</h3>
                @if($ownedTeams->isEmpty())
                    <div class="bg-gray-800/50 border border-gray-700/60 border-dashed rounded-xl p-8">
                        <div class="max-w-sm mx-auto text-center">
                            <div class="w-12 h-12 rounded-xl bg-gray-700/60 border border-gray-600 flex items-center justify-center mx-auto mb-4">
                                <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                            <p class="text-gray-300 font-medium mb-1">Create a shared workspace</p>
                            <p class="text-gray-500 text-sm mb-5">Invite collaborators to manage galleries together. Each team gets its own gallery space separate from your personal galleries.</p>
                            <a href="{{ route('admin.teams.create') }}"
                               class="btn btn-secondary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Create your first team
                            </a>
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($ownedTeams as $team)
                        @php $isActive = auth()->user()->current_team_id === $team->id; @endphp
                        <div class="bg-gray-800 border {{ $isActive ? 'border-brand-500/50' : 'border-gray-700 hover:border-gray-600' }} rounded-xl p-5 transition group relative">
                            @if($isActive)
                                <div class="absolute top-3 right-3 flex items-center gap-1 text-xs text-emerald-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                                    Active
                                </div>
                            @endif
                            <div class="flex items-start gap-3 mb-3">
                                <div class="w-9 h-9 rounded-lg bg-brand-700 flex items-center justify-center text-white font-semibold text-sm flex-shrink-0">
                                    {{ strtoupper(substr($team->name, 0, 1)) }}
                                </div>
                                <div class="min-w-0 flex-1 pr-12">
                                    <h4 class="text-white font-semibold leading-tight truncate">{{ $team->name }}</h4>
                                    @if($team->description)
                                        <p class="text-gray-500 text-xs mt-0.5 line-clamp-1">{{ $team->description }}</p>
                                    @else
                                        <p class="text-gray-600 text-xs mt-0.5">No description</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-xs text-gray-500 mb-4">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                    {{ $team->members_count }} member{{ $team->members_count !== 1 ? 's' : '' }}
                                </span>
                                <span class="text-xs bg-purple-900/40 text-purple-400 border border-purple-800/40 px-1.5 py-0.5 rounded-full">Owner</span>
                            </div>
                            <div class="flex gap-2">
                                <a href="{{ route('admin.teams.show', $team) }}" class="flex-1 text-center text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 py-2 px-3 rounded-lg transition">Manage</a>
                                @if(!$isActive)
                                <form action="{{ route('admin.teams.switch', $team) }}" method="POST" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full text-xs bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 py-2 px-3 rounded-lg transition border border-indigo-700/30">
                                        Switch here
                                    </button>
                                </form>
                                @else
                                <a href="{{ route('admin.galleries.index') }}" class="flex-1 text-center text-xs bg-gray-700/50 hover:bg-gray-700 text-gray-400 py-2 px-3 rounded-lg transition">
                                    View galleries
                                </a>
                                @endif
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
                    @php $isActive = auth()->user()->current_team_id === $team->id; @endphp
                    <div class="bg-gray-800 border {{ $isActive ? 'border-brand-500/50' : 'border-gray-700 hover:border-gray-600' }} rounded-xl p-5 transition relative">
                        @if($isActive)
                            <div class="absolute top-3 right-3 flex items-center gap-1 text-xs text-green-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>
                                Active
                            </div>
                        @endif
                        <div class="flex items-start gap-3 mb-3">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-700 to-blue-700 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                {{ strtoupper(substr($team->name, 0, 1)) }}
                            </div>
                            <div class="min-w-0 flex-1 pr-12">
                                <h4 class="text-white font-semibold leading-tight truncate">{{ $team->name }}</h4>
                                <p class="text-gray-500 text-xs mt-0.5">Owned by {{ $team->owner->name }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-xs {{ $role === 'editor' ? 'bg-blue-900/40 text-blue-400 border-blue-800/40' : 'bg-gray-700 text-gray-400 border-gray-600' }} border px-1.5 py-0.5 rounded-full capitalize">{{ $role }}</span>
                            <span class="text-gray-600 text-xs">{{ $role === 'editor' ? 'Can manage galleries' : 'View only' }}</span>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('admin.teams.show', $team) }}" class="flex-1 text-center text-xs bg-gray-700 hover:bg-gray-600 text-gray-300 py-2 px-3 rounded-lg transition">View</a>
                            @if(!$isActive)
                            <form action="{{ route('admin.teams.switch', $team) }}" method="POST" class="flex-1">
                                @csrf
                                <button type="submit" class="w-full text-xs bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 py-2 px-3 rounded-lg transition border border-indigo-700/30">
                                    Switch here
                                </button>
                            </form>
                            @else
                            <a href="{{ route('admin.galleries.index') }}" class="flex-1 text-center text-xs bg-gray-700/50 hover:bg-gray-700 text-gray-400 py-2 px-3 rounded-lg transition">
                                View galleries
                            </a>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>

</x-app-layout>

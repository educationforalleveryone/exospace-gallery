<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-xl text-gray-100 leading-tight">{{ $team->name }}</h2>
                @if($team->description)
                    <p class="text-gray-400 text-sm mt-0.5">{{ $team->description }}</p>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if(auth()->user()->current_team_id !== $team->id)
                    <form action="{{ route('admin.teams.switch', $team) }}" method="POST">
                        @csrf
                        <button type="submit" class="bg-indigo-600/30 hover:bg-indigo-600/50 border border-indigo-600/50 text-indigo-300 text-sm font-medium py-2 px-4 rounded-lg transition">
                            Switch to this Team
                        </button>
                    </form>
                @else
                    <span class="bg-green-900/40 border border-green-700/50 text-green-400 text-sm font-medium py-2 px-4 rounded-lg">
                        ✓ Active Team
                    </span>
                @endif
                <a href="{{ route('admin.teams.index') }}" class="text-gray-400 hover:text-gray-200 text-sm transition">← All Teams</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            @if(session('status'))
                <div class="p-4 bg-green-900 border border-green-700 text-green-200 rounded-lg">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="p-4 bg-red-900/50 border border-red-700 text-red-300 rounded-lg">
                    <ul class="list-disc list-inside text-sm space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                {{-- LEFT COLUMN: Members + Invite --}}
                <div class="lg:col-span-2 space-y-6">

                    {{-- Members List --}}
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-700 flex justify-between items-center">
                            <h3 class="text-white font-semibold">Members ({{ $team->members->count() }})</h3>
                        </div>
                        <div class="divide-y divide-gray-700">
                            @foreach($team->members as $member)
                            @php $role = $member->pivot->role; @endphp
                            <div class="px-6 py-4 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        {{ strtoupper(substr($member->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="text-white text-sm font-medium">{{ $member->name }}
                                            @if($member->id === auth()->id()) <span class="text-gray-500 text-xs">(you)</span> @endif
                                        </p>
                                        <p class="text-gray-400 text-xs">{{ $member->email }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if($userRole === 'owner' && $member->id !== $team->owner_id)
                                        {{-- Role toggle --}}
                                        <form action="{{ route('admin.teams.update-role', $team) }}" method="POST" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                            <select name="role" onchange="this.form.submit()"
                                                    class="text-xs bg-gray-700 border border-gray-600 text-gray-300 rounded-lg px-2 py-1.5 focus:border-purple-500 outline-none">
                                                <option value="editor" {{ $role === 'editor' ? 'selected' : '' }}>Editor</option>
                                                <option value="viewer" {{ $role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                                            </select>
                                        </form>
                                        {{-- Remove member --}}
                                        <form action="{{ route('admin.teams.remove-member', $team) }}" method="POST"
                                              onsubmit="return confirm('Remove {{ $member->name }} from this team?')">
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                            <button type="submit" class="text-red-400 hover:text-red-300 text-xs transition p-1.5 hover:bg-red-900/30 rounded">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs px-2.5 py-1 rounded-full font-medium
                                            {{ $role === 'owner' ? 'bg-purple-900/50 text-purple-300 border border-purple-700/50' :
                                               ($role === 'editor' ? 'bg-blue-900/50 text-blue-300 border border-blue-700/50' :
                                                'bg-gray-700 text-gray-400 border border-gray-600') }}">
                                            {{ ucfirst($role) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Invite Form (owner only) --}}
                    @if($userRole === 'owner')
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                        <h3 class="text-white font-semibold mb-4">Invite a Collaborator</h3>
                        <form action="{{ route('admin.teams.invite', $team) }}" method="POST" class="flex gap-3 flex-wrap">
                            @csrf
                            <input type="email" name="email" placeholder="colleague@example.com" required
                                   class="flex-1 min-w-[200px] bg-gray-700 border border-gray-600 rounded-lg px-4 py-2.5 text-white placeholder-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition text-sm">
                            <select name="role" class="bg-gray-700 border border-gray-600 text-gray-300 rounded-lg px-3 py-2.5 focus:border-purple-500 outline-none text-sm">
                                <option value="editor">Editor — can manage galleries</option>
                                <option value="viewer">Viewer — read-only access</option>
                            </select>
                            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2.5 px-5 rounded-lg transition text-sm whitespace-nowrap">
                                Send Invite
                            </button>
                        </form>
                        <p class="text-gray-500 text-xs mt-2">An invitation link valid for 7 days will be sent to their email.</p>
                    </div>

                    {{-- Pending Invitations --}}
                    @if($pendingInvitations->isNotEmpty())
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-700">
                            <h3 class="text-white font-semibold">Pending Invitations ({{ $pendingInvitations->count() }})</h3>
                        </div>
                        <div class="divide-y divide-gray-700">
                            @foreach($pendingInvitations as $inv)
                            <div class="px-6 py-3 flex items-center justify-between">
                                <div>
                                    <p class="text-gray-200 text-sm">{{ $inv->email }}</p>
                                    <p class="text-gray-500 text-xs">
                                        <span class="capitalize">{{ $inv->role }}</span> ·
                                        Expires {{ $inv->expires_at->diffForHumans() }}
                                    </p>
                                </div>
                                <form action="{{ route('admin.teams.revoke-invitation', [$team, $inv]) }}" method="POST"
                                      onsubmit="return confirm('Revoke this invitation?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:text-red-300 border border-red-800/50 hover:border-red-700 px-3 py-1.5 rounded-lg transition">
                                        Revoke
                                    </button>
                                </form>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @endif

                    {{-- Leave Team (non-owner only) --}}
                    @if($userRole !== 'owner')
                    <div class="bg-red-950/30 border border-red-900/50 rounded-xl p-5">
                        <h4 class="text-red-300 font-medium mb-2">Leave Team</h4>
                        <p class="text-gray-400 text-sm mb-3">You will lose access to all galleries in this team.</p>
                        <form action="{{ route('admin.teams.leave', $team) }}" method="POST"
                              onsubmit="return confirm('Leave {{ $team->name }}? You will lose access to team galleries.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm bg-red-900/50 hover:bg-red-900/80 text-red-300 border border-red-800/50 py-2 px-4 rounded-lg transition">
                                Leave Team
                            </button>
                        </form>
                    </div>
                    @endif

                </div>

                {{-- RIGHT COLUMN: Team Settings + Recent Galleries --}}
                <div class="space-y-6">

                    {{-- Team Settings (owner only) --}}
                    @if($userRole === 'owner')
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                        <h3 class="text-white font-semibold mb-4">Team Settings</h3>
                        <form action="{{ route('admin.teams.update', $team) }}" method="POST" class="space-y-4">
                            @csrf @method('PATCH')
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Team Name</label>
                                <input type="text" name="name" value="{{ old('name', $team->name) }}" required maxlength="100"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1.5">Description</label>
                                <textarea name="description" rows="2" maxlength="500"
                                          class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none transition resize-none">{{ old('description', $team->description) }}</textarea>
                            </div>
                            <button type="submit" class="w-full bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm font-medium py-2 px-4 rounded-lg transition">
                                Save Changes
                            </button>
                        </form>
                    </div>
                    @endif

                    {{-- Recent Team Galleries --}}
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-5 py-4 border-b border-gray-700 flex justify-between items-center">
                            <h3 class="text-white font-semibold text-sm">Team Galleries</h3>
                            @if($team->canEdit(auth()->user()))
                                <a href="{{ route('admin.galleries.create') }}?team={{ $team->id }}"
                                   class="text-xs text-purple-400 hover:text-purple-300 transition">+ New</a>
                            @endif
                        </div>
                        @if($team->galleries->isEmpty())
                            <div class="px-5 py-6 text-center">
                                <p class="text-gray-500 text-sm">No galleries yet.</p>
                                @if($team->canEdit(auth()->user()))
                                    <a href="{{ route('admin.galleries.create') }}?team={{ $team->id }}"
                                       class="text-purple-400 hover:text-purple-300 text-sm mt-1 inline-block">Create the first one →</a>
                                @endif
                            </div>
                        @else
                            <div class="divide-y divide-gray-700">
                                @foreach($team->galleries as $gallery)
                                <div class="px-5 py-3 flex items-center justify-between">
                                    <div>
                                        <p class="text-gray-200 text-sm font-medium">{{ $gallery->title }}</p>
                                        <p class="text-gray-500 text-xs">{{ $gallery->view_count }} views</p>
                                    </div>
                                    <a href="{{ route('admin.galleries.edit', $gallery) }}"
                                       class="text-xs text-gray-400 hover:text-purple-300 transition">Edit →</a>
                                </div>
                                @endforeach
                            </div>
                            <div class="px-5 py-3 border-t border-gray-700">
                                <a href="{{ route('admin.galleries.index') }}?team={{ $team->id }}" class="text-xs text-gray-400 hover:text-purple-300 transition">View all galleries →</a>
                            </div>
                        @endif
                    </div>

                    {{-- Danger Zone (owner only) --}}
                    @if($userRole === 'owner')
                    <div class="bg-red-950/30 border border-red-900/50 rounded-xl p-5">
                        <h4 class="text-red-300 font-medium mb-2 text-sm">Danger Zone</h4>
                        <p class="text-gray-400 text-xs mb-3">Deleting a team is permanent. Galleries will not be deleted but will become unowned.</p>
                        <form action="{{ route('admin.teams.destroy', $team) }}" method="POST"
                              onsubmit="return confirm('Permanently delete this team? This cannot be undone.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs bg-red-900/50 hover:bg-red-900/80 text-red-300 border border-red-800/50 py-2 px-4 rounded-lg transition">
                                Delete Team
                            </button>
                        </form>
                    </div>
                    @endif

                </div>
            </div>
        </div>
    </div>
</x-app-layout>

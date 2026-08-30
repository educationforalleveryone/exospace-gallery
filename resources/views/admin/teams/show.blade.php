<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:justify-between sm:items-start">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-brand-600 flex items-center justify-center text-white font-semibold text-base shrink-0" aria-hidden="true">
                    {{ strtoupper(substr($team->name, 0, 1)) }}
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="page-title break-words">{{ $team->name }}</h1>
                        <span class="badge {{ $userRole === 'owner' ? 'badge-brand' :
                               ($userRole === 'editor' ? 'badge-info' : 'badge-neutral') }}">
                            {{ ucfirst($userRole) }}
                        </span>
                        @if(auth()->user()->current_team_id === $team->id)
                            <span class="text-xs text-emerald-400 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block" aria-hidden="true"></span>
                                Active
                            </span>
                        @endif
                    </div>
                    @if($team->description)
                        <p class="text-gray-500 text-sm mt-0.5">{{ $team->description }}</p>
                    @else
                        <p class="text-gray-600 text-sm mt-0.5">{{ $team->members->count() }} member{{ $team->members->count() !== 1 ? 's' : '' }} · owned by {{ $team->owner->name }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if(auth()->user()->current_team_id !== $team->id)
                    <form action="{{ route('admin.teams.switch', $team) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            Switch here
                        </button>
                    </form>
                @endif
                <a href="{{ route('admin.teams.index') }}" class="text-gray-500 hover:text-gray-300 text-sm transition flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    All Teams
                </a>
            </div>
        </div>
    </x-slot>

    <div class="page-shell space-y-8">

            @if($errors->any())
                <div class="flex items-start gap-3 p-4 bg-red-950/50 border border-red-700/60 text-red-300 rounded-xl">
                    <svg class="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <ul class="text-sm space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                {{-- LEFT COLUMN: Members + Invite --}}
                <div class="lg:col-span-2 space-y-6">

                    {{-- Members List --}}
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-700 flex justify-between items-center">
                            <h3 class="text-white font-semibold">Members <span class="text-gray-500 font-normal text-sm ml-1">{{ $team->members->count() }}</span></h3>
                            @if($userRole === 'owner')
                                <p class="text-gray-600 text-xs">Changes save immediately</p>
                            @endif
                        </div>
                        <div class="divide-y divide-gray-700/60">
                            @foreach($team->members as $member)
                            @php $role = $member->pivot->role; $isYou = $member->id === auth()->id(); @endphp
                            <div class="px-6 py-3.5 flex items-center justify-between gap-4 {{ $isYou ? 'bg-gray-800/60' : '' }}">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-8 h-8 rounded-full {{ $member->id === $team->owner_id ? 'bg-brand-600' : 'bg-gray-700 border border-gray-600' }} flex items-center justify-center text-white font-bold text-xs flex-shrink-0">
                                        {{ strtoupper(substr($member->name, 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-200 flex items-center gap-1.5">
                                            <span class="truncate">{{ $member->name }}</span>
                                            @if($isYou) <span class="text-gray-600 text-xs font-normal flex-shrink-0">(you)</span> @endif
                                        </p>
                                        <p class="text-gray-500 text-xs truncate">{{ $member->email }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    @if($userRole === 'owner' && $member->id !== $team->owner_id)
                                        {{-- Role selector — submits on change with visual feedback --}}
                                        <form action="{{ route('admin.teams.update-role', $team) }}" method="POST" class="role-form">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                            <select name="role" data-change="submitRoleChange"
                                                    class="input-base input-sm cursor-pointer">
                                                <option value="editor" {{ $role === 'editor' ? 'selected' : '' }}>Editor</option>
                                                <option value="viewer" {{ $role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                                            </select>
                                        </form>
                                        {{-- Remove member — ITERATION-3: 32px hit target (was a ~26px p-1.5 icon) --}}
                                        <div x-data="{ confirming: false }" class="flex items-center">
                                            <template x-if="!confirming">
                                                <button @click="confirming = true" type="button"
                                                        class="btn btn-icon text-gray-600 hover:text-red-400 hover:bg-red-900/20" title="Remove from team" aria-label="Remove {{ $member->name }} from team">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </template>
                                            <template x-if="confirming">
                                                <div class="flex items-center gap-1.5 bg-red-950/50 border border-red-800/50 rounded-lg px-2 py-1">
                                                    <span class="text-red-300 text-xs max-w-[140px] truncate">Remove {{ Str::before($member->name, ' ') }}?</span>
                                                    <form action="{{ route('admin.teams.remove-member', $team) }}" method="POST" class="inline">
                                                        @csrf @method('DELETE')
                                                        <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                        <button type="submit" class="btn btn-sm btn-danger-ghost">Yes</button>
                                                    </form>
                                                    <button @click="confirming = false" type="button" class="btn btn-sm btn-ghost">No</button>
                                                </div>
                                            </template>
                                        </div>
                                    @else
                                        <span class="text-xs px-2 py-1 rounded-full font-medium whitespace-nowrap
                                            {{ $role === 'owner' ? 'bg-brand-900/50 text-brand-300 border border-brand-700/50' :
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
                        <h3 class="text-white font-semibold mb-1">Invite a Collaborator</h3>
                        <p class="text-gray-500 text-xs mb-5">They'll receive an email with an invitation link valid for 7 days.</p>

                        <form action="{{ route('admin.teams.invite', $team) }}" method="POST" class="space-y-4"
                              data-submit="disableSubmitButton">
                            @csrf
                            <div>
                                <label for="invite-email" class="label-text mb-1.5">Email address</label>
                                <input type="email" id="invite-email" name="email" placeholder="colleague@example.com" required
                                       value="{{ old('email') }}"
                                       class="input-base {{ $errors->has('email') ? 'input-error' : '' }}">
                                @error('email')
                                    <p class="text-red-400 text-xs mt-1.5 flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div x-data="{ role: '{{ old('role', 'editor') }}' }">
                                <label class="block text-xs font-medium text-gray-400 mb-2">Role</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="role" value="editor" x-model="role" class="sr-only">
                                        <div :class="role === 'editor' ? 'border-blue-500 bg-blue-900/20' : 'border-gray-600 bg-gray-700/40 hover:border-gray-500'"
                                             class="border rounded-lg p-3 transition">
                                            <p class="text-sm font-medium text-gray-200 mb-0.5">Editor</p>
                                            <p class="text-xs text-gray-500">Can create and manage galleries</p>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="role" value="viewer" x-model="role" class="sr-only">
                                        <div :class="role === 'viewer' ? 'border-gray-400 bg-gray-700/40' : 'border-gray-600 bg-gray-700/40 hover:border-gray-500'"
                                             class="border rounded-lg p-3 transition">
                                            <p class="text-sm font-medium text-gray-200 mb-0.5">Viewer</p>
                                            <p class="text-xs text-gray-500">Can view galleries and analytics only</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-full">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                Send Invitation
                            </button>
                        </form>
                    </div>

                    {{-- Pending Invitations --}}
                    @if($pendingInvitations->isNotEmpty())
                    <div class="bg-gray-800 border border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-700">
                            <h3 class="text-white font-semibold">Pending Invitations ({{ $pendingInvitations->count() }})</h3>
                        </div>
                        <div class="divide-y divide-gray-700">
                            @foreach($pendingInvitations as $inv)
                            <div class="px-6 py-3 flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-gray-200 text-sm truncate">{{ $inv->email }}</p>
                                    <p class="text-gray-500 text-xs">
                                        <span class="capitalize">{{ $inv->role }}</span> ·
                                        Expires {{ $inv->expires_at->diffForHumans() }}
                                    </p>
                                </div>
                                <div x-data="{ confirming: false }" class="flex-shrink-0">
                                    <template x-if="!confirming">
                                        <button @click="confirming = true" type="button" class="btn btn-sm btn-danger-ghost">Revoke</button>
                                    </template>
                                    <template x-if="confirming">
                                        <div class="flex items-center gap-1.5 bg-red-950/50 border border-red-800/50 rounded-lg px-2 py-1">
                                            <span class="text-red-300 text-xs">Revoke?</span>
                                            <form action="{{ route('admin.teams.revoke-invitation', [$team, $inv]) }}" method="POST" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger-ghost">Yes</button>
                                            </form>
                                            <button @click="confirming = false" type="button" class="btn btn-sm btn-ghost">No</button>
                                        </div>
                                    </template>
                                </div>
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
                        <div x-data="{ confirming: false }">
                            <template x-if="!confirming">
                                <button @click="confirming = true" type="button" class="btn btn-sm btn-danger-ghost">Leave Team</button>
                            </template>
                            <template x-if="confirming">
                                <div class="bg-red-950/60 border border-red-700/60 rounded-xl p-4">
                                    <p class="text-red-200 text-sm mb-3">You'll lose access to all galleries in <strong>{{ $team->name }}</strong>. Continue?</p>
                                    <div class="flex gap-2">
                                        <form action="{{ route('admin.teams.leave', $team) }}" method="POST" data-busy data-busy-label="Leaving…">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Yes, Leave</button>
                                        </form>
                                        <button @click="confirming = false" type="button" class="btn btn-sm btn-secondary">Cancel</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    @endif

                </div>

                {{-- RIGHT COLUMN: Team Settings + Recent Galleries --}}
                <div class="space-y-6">

                    {{-- Team Settings (owner only) --}}
                    @if($userRole === 'owner')
                    <div class="bg-gray-800 border border-gray-700 rounded-xl p-6">
                        <h3 class="text-white font-semibold mb-4">Team Settings</h3>
                        <form action="{{ route('admin.teams.update', $team) }}" method="POST" class="space-y-4" data-busy data-busy-label="Saving…">
                            @csrf @method('PATCH')
                            <div>
                                <label for="team-name" class="label-text mb-1.5">Team Name</label>
                                <input type="text" id="team-name" name="name" value="{{ old('name', $team->name) }}" required maxlength="100" class="input-base">
                            </div>
                            <div>
                                <label for="team-description" class="label-text mb-1.5">Description</label>
                                {{-- ITERATION-3 CRITICAL FIX: the opening <textarea> tag was missing
                                     its closing ">" — the old() value rendered as broken attributes and
                                     the whole Team Settings form submitted corrupted markup. --}}
                                <textarea name="description" id="team-description" rows="3" class="input-base">{{ old('description', $team->description) }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-secondary w-full">
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
                                   class="text-xs text-brand-400 hover:text-brand-300 transition">+ New</a>
                            @endif
                        </div>
                        @if($team->galleries->isEmpty())
                            <div class="px-5 py-6 text-center">
                                <p class="text-gray-500 text-sm">No galleries yet.</p>
                                @if($team->canEdit(auth()->user()))
                                    <a href="{{ route('admin.galleries.create') }}?team={{ $team->id }}"
                                       class="text-brand-400 hover:text-brand-300 text-sm mt-1 inline-block">Create the first one →</a>
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
                                    @if($team->canEdit(auth()->user()))
                                        <a href="{{ route('admin.galleries.edit', $gallery) }}"
                                           class="text-xs text-gray-400 hover:text-brand-300 transition">Edit →</a>
                                    @else
                                        <a href="{{ route('gallery.view', $gallery->slug) }}" target="_blank"
                                           class="text-xs text-gray-500 hover:text-gray-300 transition">View →</a>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                            <div class="px-5 py-3 border-t border-gray-700 flex items-center justify-between">
                                <a href="{{ route('admin.galleries.index') }}?team={{ $team->id }}" class="text-xs text-gray-400 hover:text-brand-300 transition flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                                    View all galleries
                                </a>
                                @if($team->canEdit(auth()->user()))
                                <a href="{{ route('admin.galleries.create') }}?team={{ $team->id }}"
                                   class="text-xs bg-brand-600/20 hover:bg-brand-600/30 text-brand-400 border border-brand-700/30 px-2.5 py-1 rounded-lg transition">
                                    + New
                                </a>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Danger Zone (owner only) --}}
                    @if($userRole === 'owner')
                    <div class="bg-red-950/30 border border-red-900/50 rounded-xl p-5">
                        <h4 class="text-red-300 font-medium mb-2 text-sm">Danger Zone</h4>
                        <p class="text-gray-400 text-xs mb-3">Deleting a team is permanent. Galleries will not be deleted but will become unowned.</p>
                        <div x-data="{ confirming: false, typed: '' }">
                            <template x-if="!confirming">
                                <button @click="confirming = true" type="button" class="btn btn-sm btn-danger-ghost">Delete Team</button>
                            </template>
                            <template x-if="confirming">
                                <div class="bg-red-950/60 border border-red-700/60 rounded-xl p-4 space-y-3">
                                    <p class="text-red-200 text-xs">This is permanent. Type <strong class="text-red-300 font-mono">{{ $team->name }}</strong> to confirm.</p>
                                    <input x-model="typed" type="text" placeholder="{{ $team->name }}"
                                           class="input-base input-sm">
                                    <div class="flex gap-2">
                                        <form action="{{ route('admin.teams.destroy', $team) }}" method="POST">
                                            @csrf @method('DELETE')
                                            {{-- ITERATION-3: @js() escapes the team name — a name containing a
                                                 single quote previously broke the Alpine expression and left
                                                 the destructive button permanently enabled. --}}
                                            <button type="submit" :disabled="typed !== @js($team->name)"
                                                    class="btn btn-sm btn-danger">
                                                Delete Permanently
                                            </button>
                                        </form>
                                        <button @click="confirming = false; typed = ''" type="button" class="btn btn-sm btn-secondary">Cancel</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    @endif

                </div>
            </div>
    </div>
    <script nonce="@nonce">
    function submitRoleChange(select) {
        const form = select.closest('form');
        select.disabled = true;
        select.style.opacity = '0.6';
        window.exospaceGuardForm(form);
        form.submit();
    }
    // ITERATION-3: window.disableSubmitButton is now the canonical helper in
    // resources/js/app.js (was defined only here, so other pages referencing
    // it silently no-op'd). The page-local copy was removed.
    </script>
</x-app-layout>

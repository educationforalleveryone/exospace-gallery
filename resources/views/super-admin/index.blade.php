<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>🎯 Master Control - ExoSpace</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gradient-to-br from-gray-900 via-black to-gray-900 text-white min-h-screen">

    <!-- Header -->
    <div class="bg-black/50 backdrop-blur-md border-b border-red-500/30">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">
                        🎯 MASTER CONTROL
                    </h1>
                    <p class="text-gray-400 text-sm">God Mode • Super Admin Dashboard</p>
                </div>
                <div class="flex gap-4 items-center">
                    <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg transition text-sm">
                        ← Dashboard
                    </a>
                    <a href="{{ route('super.venues.index') }}" class="px-4 py-2 bg-purple-800 hover:bg-purple-700 rounded-lg transition text-sm">
                        🏛️ Venue Templates
                    </a>
                    <a href="{{ route('super.featured.index') }}" class="px-4 py-2 bg-amber-700 hover:bg-amber-600 rounded-lg transition text-sm">
                        ⭐ Featured
                    </a>
                    <a href="{{ route('super.pending-upgrades.index') }}" class="px-4 py-2 bg-blue-800 hover:bg-blue-700 rounded-lg transition text-sm">
                        💳 Pending Upgrades
                    </a>
                    <a href="{{ route('super.feedback.index') }}" class="px-4 py-2 bg-teal-800 hover:bg-teal-700 rounded-lg transition text-sm">
                        💬 Feedback
                    </a>
                    <a href="{{ route('super.nps.index') }}" class="px-4 py-2 bg-pink-800 hover:bg-pink-700 rounded-lg transition text-sm">
                        📊 NPS
                    </a>
                    <a href="{{ route('super.affiliates.index') }}" class="px-4 py-2 bg-green-800 hover:bg-green-700 rounded-lg transition text-sm">
                        🤝 Affiliates
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg transition text-sm">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <div class="max-w-7xl mx-auto px-6 mt-4 space-y-2">
        @if(session('success'))
            <div class="bg-green-500/20 border border-green-500 text-green-300 px-4 py-3 rounded-lg flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-500/20 border border-red-500 text-red-300 px-4 py-3 rounded-lg flex items-center gap-2">
                ❌ {{ session('error') }}
            </div>
        @endif
    </div>

    <!-- Platform Statistics -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-xl font-bold mb-4 text-gray-300 uppercase tracking-wider text-sm">📊 Platform Statistics</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-9 gap-3 mb-10">
            @foreach([
                ['val' => $stats['total_users'],     'label' => 'Total Users',    'color' => 'blue'],
                ['val' => $stats['total_galleries'],  'label' => 'Galleries',      'color' => 'purple'],
                ['val' => $stats['total_images'],     'label' => 'Images',         'color' => 'indigo'],
                ['val' => number_format($stats['total_views']), 'label' => 'Views', 'color' => 'pink'],
                ['val' => $stats['free_users'],       'label' => 'Free',           'color' => 'gray'],
                ['val' => $stats['pro_users'],        'label' => 'Pro',            'color' => 'yellow'],
                ['val' => $stats['studio_users'],     'label' => 'Studio',         'color' => 'purple'],
                ['val' => $stats['banned_users'],     'label' => 'Banned',         'color' => 'red'],
                ['val' => $stats['unverified_users'], 'label' => 'Unverified',     'color' => 'orange'],
            ] as $stat)
            <div class="bg-{{ $stat['color'] }}-900/30 border border-{{ $stat['color'] }}-700/30 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-{{ $stat['color'] }}-300">{{ $stat['val'] }}</div>
                <div class="text-xs text-gray-400 mt-0.5">{{ $stat['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- M-14: Feature Flags status panel --}}
        <div class="mb-8 bg-gray-900/50 border border-gray-700/30 rounded-lg p-4">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">🚩 Feature Flags</h3>
            <div class="flex flex-wrap gap-2">
                @php $flags = \App\Services\FeatureFlag::all(); @endphp
                @foreach($flags as $name => $enabled)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                 {{ $enabled ? 'bg-emerald-900/40 text-emerald-300 border border-emerald-700/30' : 'bg-gray-800 text-gray-500 border border-gray-700' }}">
                        @if($enabled)
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        @else
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                        @endif
                        {{ $name }}
                    </span>
                @endforeach
            </div>
        </div>

        <!-- Search / Filter -->
        <div class="flex gap-3 mb-4">
            <input type="text" id="userSearch" placeholder="Search by name or email..."
                   class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-sm text-white placeholder-gray-500 focus:border-red-500 outline-none">
            <select id="planFilter" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-red-500 outline-none">
                <option value="">All Plans</option>
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="studio">Studio</option>
            </select>
            <select id="statusFilter" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-red-500 outline-none">
                <option value="">All Status</option>
                <option value="banned">Banned</option>
                <option value="unverified">Unverified</option>
                <option value="verified">Verified</option>
            </select>
        </div>

        <!-- Users Table -->
        <h2 class="text-xl font-bold mb-4 text-gray-300 uppercase tracking-wider text-sm">👥 All Users</h2>
        <div class="bg-black/40 border border-gray-700 rounded-xl overflow-hidden">
            <table class="w-full" id="usersTable">
                <thead class="bg-gray-800/60 border-b border-gray-700">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">User</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Plan</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Galleries</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Joined</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800" id="usersBody">
                    @foreach($users as $user)
                    @php
                        $isBanned    = ! is_null($user->banned_at);
                        $isVerified  = ! is_null($user->email_verified_at);
                        $isSelf      = $user->id === auth()->id();
                    @endphp
                    <tr class="hover:bg-gray-800/20 transition user-row"
                        data-name="{{ strtolower($user->name) }}"
                        data-email="{{ strtolower($user->email) }}"
                        data-plan="{{ $user->plan }}"
                        data-banned="{{ $isBanned ? 'banned' : '' }}"
                        data-verified="{{ $isVerified ? 'verified' : 'unverified' }}">

                        {{-- User --}}
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center font-bold text-sm flex-shrink-0">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="font-medium text-white flex items-center gap-2">
                                        {{ $user->name }}
                                        @if($isSelf)
                                            <span class="text-xs bg-blue-600 px-1.5 py-0.5 rounded">YOU</span>
                                        @endif
                                        @if($user->is_super_admin)
                                            <span class="text-xs bg-red-600 px-1.5 py-0.5 rounded">ADMIN</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-400">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- Status badges --}}
                        <td class="px-5 py-4">
                            <div class="flex flex-col gap-1">
                                @if($isBanned)
                                    <span class="text-xs bg-red-900/60 border border-red-700/50 text-red-300 px-2 py-0.5 rounded-full w-fit">🚫 Banned</span>
                                @endif
                                @if($isVerified)
                                    <span class="text-xs bg-green-900/40 border border-green-700/30 text-green-400 px-2 py-0.5 rounded-full w-fit">✓ Verified</span>
                                @else
                                    <span class="text-xs bg-yellow-900/40 border border-yellow-700/30 text-yellow-400 px-2 py-0.5 rounded-full w-fit">⚠ Unverified</span>
                                @endif
                            </div>
                        </td>

                        {{-- Plan --}}
                        <td class="px-5 py-4">
                            @if(! $isSelf)
                            <form method="POST" action="{{ route('super.updatePlan', $user) }}">
                                @csrf
                                <select name="plan" onchange="if(confirm('Change plan for {{ addslashes($user->name) }}?')) this.form.submit();"
                                        class="bg-gray-700 border border-gray-600 rounded-lg px-2 py-1 text-xs text-white focus:border-red-500 outline-none">
                                    <option value="free"   {{ $user->plan === 'free'   ? 'selected' : '' }}>FREE</option>
                                    <option value="pro"    {{ $user->plan === 'pro'    ? 'selected' : '' }}>PRO</option>
                                    <option value="studio" {{ $user->plan === 'studio' ? 'selected' : '' }}>STUDIO</option>
                                </select>
                            </form>
                            @else
                                <span class="text-xs px-2 py-1 rounded-lg
                                    {{ $user->plan === 'free' ? 'bg-gray-700 text-gray-300' : '' }}
                                    {{ $user->plan === 'pro' ? 'bg-yellow-800/60 text-yellow-300' : '' }}
                                    {{ $user->plan === 'studio' ? 'bg-purple-800/60 text-purple-300' : '' }}">
                                    {{ strtoupper($user->plan) }}
                                </span>
                            @endif
                        </td>

                        {{-- Galleries --}}
                        <td class="px-5 py-4">
                            <a href="{{ route('super.user-galleries', $user) }}" class="text-blue-400 hover:text-blue-300 text-sm transition">
                                {{ $user->galleries_count }} galleries →
                            </a>
                        </td>

                        {{-- Joined --}}
                        <td class="px-5 py-4 text-gray-400 text-xs">
                            {{ $user->created_at->format('M j, Y') }}<br>
                            <span class="text-gray-600">{{ $user->created_at->diffForHumans() }}</span>
                        </td>

                        {{-- Actions --}}
                        <td class="px-5 py-4">
                            @if(! $isSelf)
                            <div class="flex flex-wrap gap-1.5">

                                {{-- Ban / Unban --}}
                                @if($isBanned)
                                    <form method="POST" action="{{ route('super.unbanUser', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                onclick="return confirm('Unban {{ addslashes($user->name) }}?')"
                                                class="px-3 py-1.5 bg-green-700 hover:bg-green-600 rounded-lg text-xs transition">
                                            ✅ Unban
                                        </button>
                                    </form>
                                @else
                                    <button onclick="openBanModal({{ $user->id }}, '{{ addslashes($user->name) }}')"
                                            class="px-3 py-1.5 bg-orange-700 hover:bg-orange-600 rounded-lg text-xs transition">
                                        🚫 Ban
                                    </button>
                                @endif

                                {{-- Verify / Unverify email --}}
                                @if(! $isVerified)
                                    <form method="POST" action="{{ route('super.verifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                onclick="return confirm('Manually verify email for {{ addslashes($user->name) }}?')"
                                                class="px-3 py-1.5 bg-teal-700 hover:bg-teal-600 rounded-lg text-xs transition">
                                            ✉ Verify
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('super.unverifyEmail', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                onclick="return confirm('Revoke email verification for {{ addslashes($user->name) }}? They will need to verify again.')"
                                                class="px-3 py-1.5 bg-gray-600 hover:bg-gray-500 rounded-lg text-xs transition">
                                            ✉ Unverify
                                        </button>
                                    </form>
                                @endif

                                {{-- Toggle Super Admin --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            onclick="openAdminModal({{ $user->id }}, '{{ addslashes($user->name) }}', 'grant')"
                                            class="px-3 py-1.5 bg-purple-800 hover:bg-purple-700 rounded-lg text-xs transition">
                                        👑 Make Admin
                                    </button>
                                @else
                                    <button type="button"
                                            onclick="openAdminModal({{ $user->id }}, '{{ addslashes($user->name) }}', 'revoke')"
                                            class="px-3 py-1.5 bg-purple-900/50 border border-purple-700 hover:bg-purple-800 rounded-lg text-xs transition text-purple-300">
                                        👑 Revoke Admin
                                    </button>
                                @endif

                                {{-- M-13: Impersonate (Login As User) --}}
                                @featureFlag('admin_impersonation')
                                @if(! $user->is_super_admin)
                                    <form method="POST" action="{{ route('super.impersonate', $user) }}">
                                        @csrf
                                        <button type="submit"
                                                onclick="return confirm('Log in as {{ addslashes($user->name) }}? You will see the site from their perspective. Click &quot;Return to admin&quot; to stop.')"
                                                class="px-3 py-1.5 bg-indigo-700 hover:bg-indigo-600 rounded-lg text-xs transition">
                                            🔑 Login As
                                        </button>
                                    </form>
                                @endif
                                @endfeatureFlag

                                {{-- Delete --}}
                                @if(! $user->is_super_admin)
                                    <button type="button"
                                            onclick="openDeleteModal({{ $user->id }}, '{{ addslashes($user->name) }}')"
                                            class="px-3 py-1.5 bg-red-700 hover:bg-red-600 rounded-lg text-xs transition">
                                        🗑 Delete
                                    </button>
                                @endif

                            </div>
                            @else
                                <span class="text-xs text-gray-600">— your account —</span>
                            @endif

                            {{-- Ban reason tooltip --}}
                            @if($isBanned && $user->ban_reason)
                                <div class="mt-1 text-xs text-red-400/70 italic">
                                    Reason: {{ Str::limit($user->ban_reason, 60) }}
                                </div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ban Modal -->
    <div id="banModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center px-4">
        <div class="bg-gray-900 border border-red-700/50 rounded-2xl p-6 w-full max-w-md shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-1">Ban User</h3>
            <p class="text-gray-400 text-sm mb-4">Banning <strong id="banUserName" class="text-white"></strong>. They will be blocked from logging in.</p>
            <form id="banForm" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm text-gray-400 mb-1.5">Reason <span class="text-gray-600">(optional)</span></label>
                    <textarea name="reason" rows="3" placeholder="e.g. Violation of terms of service"
                              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm placeholder-gray-600 focus:border-red-500 outline-none resize-none"></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 py-2.5 rounded-lg font-medium transition text-sm">
                        🚫 Confirm Ban
                    </button>
                    <button type="button" onclick="closeBanModal()" class="px-5 bg-gray-700 hover:bg-gray-600 py-2.5 rounded-lg transition text-sm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Ban modal
        function openBanModal(userId, userName) {
            document.getElementById('banUserName').textContent = userName;
            document.getElementById('banForm').action = '/master-control/users/' + userId + '/ban';
            document.getElementById('banModal').classList.remove('hidden');
        }
        function closeBanModal() {
            document.getElementById('banModal').classList.add('hidden');
        }
        document.getElementById('banModal').addEventListener('click', function(e) {
            if (e.target === this) closeBanModal();
        });

        // Search & filter
        const search     = document.getElementById('userSearch');
        const planFilter = document.getElementById('planFilter');
        const statusFilter = document.getElementById('statusFilter');

        function applyFilters() {
            const q      = search.value.toLowerCase();
            const plan   = planFilter.value;
            const status = statusFilter.value;

            document.querySelectorAll('.user-row').forEach(row => {
                const matchesSearch = ! q || row.dataset.name.includes(q) || row.dataset.email.includes(q);
                const matchesPlan   = ! plan || row.dataset.plan === plan;
                const matchesStatus = ! status
                    || (status === 'banned'     && row.dataset.banned === 'banned')
                    || (status === 'unverified' && row.dataset.verified === 'unverified')
                    || (status === 'verified'   && row.dataset.verified === 'verified');

                row.style.display = (matchesSearch && matchesPlan && matchesStatus) ? '' : 'none';
            });
        }

        search.addEventListener('input', applyFilters);
        planFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);
    </script>


    {{-- (Task H32) Type-to-confirm modals for destructive super-admin actions --}}
    <div id="deleteConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '' }"
         x-cloak
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="delete-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="bg-gray-900 border border-red-700/50 rounded-2xl max-w-md w-full shadow-2xl p-6 relative">
            <button @click="open = false; typed = ''" class="absolute top-3 right-3 text-gray-500 hover:text-gray-300" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="delete-modal-heading" class="text-lg font-bold text-red-400 mb-3">Permanently Delete User</h3>
            <div class="text-sm text-gray-400 mb-4 space-y-2">
                <p>You are about to <strong class="text-red-400">permanently delete</strong> <strong x-text="userName" class="text-white"></strong>.</p>
                <p>This will delete:</p>
                <ul class="list-disc list-inside text-gray-500 ml-2 space-y-0.5">
                    <li>User account</li>
                    <li>All personal galleries &amp; images</li>
                    <li>All teams they own</li>
                    <li>All files from storage</li>
                </ul>
                <p class="text-red-400 font-semibold">This CANNOT be undone.</p>
            </div>
            <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
                <label for="delete-confirm-input" class="block text-xs text-gray-500 mb-1">
                    Type <code class="text-gray-300 font-mono">DELETE</code> to confirm
                </label>
                <input id="delete-confirm-input" type="text" x-model="typed" :placeholder="userName"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId" method="POST" id="deleteForm">
                @csrf
                <input type="hidden" name="_method" value="DELETE">
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">Cancel</button>
                    <button type="submit" :disabled="typed !== 'DELETE'"
                            class="flex-1 bg-red-600 hover:bg-red-500 text-white font-bold py-2.5 rounded-xl transition text-sm disabled:opacity-40 disabled:cursor-not-allowed">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="adminConfirmModal" x-data="{ open: false, typed: '', userId: 0, userName: '', action: 'grant' }"
         x-cloak
         class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] hidden items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="admin-modal-heading"
         :class="open ? 'flex' : 'hidden'"
         @keydown.escape.window="open = false; typed = ''"
         @click.self="open = false; typed = ''">
        <div class="bg-gray-900 border border-purple-700/50 rounded-2xl max-w-md w-full shadow-2xl p-6 relative">
            <button @click="open = false; typed = ''" class="absolute top-3 right-3 text-gray-500 hover:text-gray-300" aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 id="admin-modal-heading" class="text-lg font-bold text-purple-400 mb-3"
                x-text="action === 'grant' ? 'Grant Super Admin' : 'Revoke Super Admin'"></h3>
            <div class="text-sm text-gray-400 mb-4 space-y-2">
                <p x-show="action === 'grant'">
                    You are about to grant <strong class="text-purple-400">super admin access</strong> to <strong x-text="userName" class="text-white"></strong>.
                    They will have full platform access including the ability to delete users, change plans, and modify any gallery.
                </p>
                <p x-show="action === 'revoke'">
                    You are about to <strong class="text-purple-400">revoke super admin access</strong> from <strong x-text="userName" class="text-white"></strong>.
                    They will lose access to /master-control/* immediately.
                </p>
            </div>
            <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
                <label for="admin-confirm-input" class="block text-xs text-gray-500 mb-1">
                    Type <code class="text-gray-300 font-mono" x-text="action === 'grant' ? 'GRANT' : 'REVOKE'"></code> to confirm
                </label>
                <input id="admin-confirm-input" type="text" x-model="typed"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none"
                    autocomplete="off">
            </div>
            <form :action="'/master-control/users/' + userId + '/toggle-super-admin'" method="POST" id="adminForm">
                @csrf
                <div class="flex gap-3">
                    <button type="button" @click="open = false; typed = ''"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">Cancel</button>
                    <button type="submit"
                            :disabled="(action === 'grant' && typed !== 'GRANT') || (action === 'revoke' && typed !== 'REVOKE')"
                            class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-bold py-2.5 rounded-xl transition text-sm disabled:opacity-40 disabled:cursor-not-allowed"
                            x-text="action === 'grant' ? 'Grant Access' : 'Revoke Access'"></button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // (Task H32) Modal openers for type-to-confirm destructive actions
    function openDeleteModal(userId, userName) {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal.__x) {
            modal.__x.$data.open = true;
            modal.__x.$data.userId = userId;
            modal.__x.$data.userName = userName;
            modal.__x.$data.typed = '';
        }
        setTimeout(() => document.getElementById('delete-confirm-input')?.focus(), 100);
    }

    function openAdminModal(userId, userName, action) {
        const modal = document.getElementById('adminConfirmModal');
        if (modal.__x) {
            modal.__x.$data.open = true;
            modal.__x.$data.userId = userId;
            modal.__x.$data.userName = userName;
            modal.__x.$data.action = action;
            modal.__x.$data.typed = '';
        }
        setTimeout(() => document.getElementById('admin-confirm-input')?.focus(), 100);
    }
    </script>

</body>
</html>
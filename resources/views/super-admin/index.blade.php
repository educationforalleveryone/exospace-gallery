<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>🎯 Master Control - ExoSpace</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <a href="{{ route('dashboard') }}" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg transition">
                        ← Back to Dashboard
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg transition">
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    @if(session('success'))
        <div class="max-w-7xl mx-auto px-6 mt-4">
            <div class="bg-green-500/20 border border-green-500 text-green-300 px-4 py-3 rounded-lg">
                ✅ {{ session('success') }}
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="max-w-7xl mx-auto px-6 mt-4">
            <div class="bg-red-500/20 border border-red-500 text-red-300 px-4 py-3 rounded-lg">
                ❌ {{ session('error') }}
            </div>
        </div>
    @endif

    <!-- Platform Statistics -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-2xl font-bold mb-6">📊 Platform Statistics</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-12">
            <div class="bg-gradient-to-br from-blue-600/20 to-blue-800/20 border border-blue-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['total_users'] }}</div>
                <div class="text-sm text-gray-400">Total Users</div>
            </div>
            <div class="bg-gradient-to-br from-purple-600/20 to-purple-800/20 border border-purple-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['total_galleries'] }}</div>
                <div class="text-sm text-gray-400">Total Galleries</div>
            </div>
            <div class="bg-gradient-to-br from-green-600/20 to-green-800/20 border border-green-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['free_users'] }}</div>
                <div class="text-sm text-gray-400">Free Plan</div>
            </div>
            <div class="bg-gradient-to-br from-yellow-600/20 to-yellow-800/20 border border-yellow-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['pro_users'] }}</div>
                <div class="text-sm text-gray-400">Pro Plan</div>
            </div>
            <div class="bg-gradient-to-br from-red-600/20 to-red-800/20 border border-red-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['studio_users'] }}</div>
                <div class="text-sm text-gray-400">Studio Plan</div>
            </div>
            <div class="bg-gradient-to-br from-indigo-600/20 to-indigo-800/20 border border-indigo-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ $stats['total_images'] }}</div>
                <div class="text-sm text-gray-400">Total Images</div>
            </div>
            <div class="bg-gradient-to-br from-pink-600/20 to-pink-800/20 border border-pink-500/30 rounded-lg p-4">
                <div class="text-3xl font-bold">{{ number_format($stats['total_views']) }}</div>
                <div class="text-sm text-gray-400">Total Views</div>
            </div>
        </div>

        <!-- User Management Table -->
        <h2 class="text-2xl font-bold mb-6">👥 All Users</h2>
        <div class="bg-black/40 border border-gray-700 rounded-lg overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">User</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">Email</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">Plan</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">Galleries</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">Joined</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    @foreach($users as $user)
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center font-bold">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold">{{ $user->name }}</div>
                                        @if($user->is_super_admin)
                                            <span class="text-xs bg-red-500 px-2 py-0.5 rounded">SUPER ADMIN</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-300">{{ $user->email }}</td>
                            <td class="px-6 py-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    {{ $user->plan === 'free' ? 'bg-gray-600' : '' }}
                                    {{ $user->plan === 'pro' ? 'bg-yellow-600' : '' }}
                                    {{ $user->plan === 'studio' ? 'bg-purple-600' : '' }}">
                                    {{ strtoupper($user->plan) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <a href="{{ route('super.user-galleries', $user) }}" class="text-blue-400 hover:text-blue-300">
                                    {{ $user->galleries_count }} galleries
                                </a>
                            </td>
                            <td class="px-6 py-4 text-gray-400 text-sm">
                                {{ $user->created_at->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex gap-2">
                                    <!-- Change Plan Dropdown -->
                                    <form method="POST" action="{{ route('super.updatePlan', $user) }}" class="inline">
                                        @csrf
                                        <select name="plan" onchange="if(confirm('Change plan for {{ $user->name }}?')) this.form.submit();"
                                                class="bg-gray-700 border border-gray-600 rounded px-3 py-1 text-sm">
                                            <option value="free" {{ $user->plan === 'free' ? 'selected' : '' }}>Free</option>
                                            <option value="pro" {{ $user->plan === 'pro' ? 'selected' : '' }}>Pro</option>
                                            <option value="studio" {{ $user->plan === 'studio' ? 'selected' : '' }}>Studio</option>
                                        </select>
                                    </form>

                                    <!-- Delete User Button -->
                                    @if(!$user->is_super_admin)
                                        <form method="POST" action="{{ route('super.deleteUser', $user) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" 
                                                    onclick="return confirm('⚠️ DELETE {{ $user->name }}?\n\nThis will permanently delete:\n• User account\n• All galleries\n• All uploaded images\n• All data\n\nThis CANNOT be undone!')"
                                                    class="px-3 py-1 bg-red-600 hover:bg-red-700 rounded text-sm transition">
                                                🗑️ Delete
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
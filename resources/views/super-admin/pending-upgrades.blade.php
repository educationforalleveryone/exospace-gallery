@extends('layouts.app')

@section('title', 'Pending Upgrades — Super Admin')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Pending Upgrades</h1>
    <p class="text-sm text-gray-400 mb-6">Users who clicked "Upgrade" but haven't completed 2Checkout checkout. Expired or abandoned upgrades can be manually reviewed here.</p>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-4 py-3 text-sm text-emerald-300" role="status">{{ session('success') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                    <th class="px-5 py-3">User</th>
                    <th class="px-5 py-3">Plan</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Created</th>
                    <th class="px-5 py-3">Expires</th>
                    <th class="px-5 py-3">Affiliate</th>
                    <th class="px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pendingUpgrades as $pending)
                <tr class="border-b border-gray-800 last:border-0 hover:bg-gray-800/30 transition">
                    <td class="px-5 py-3">
                        <div class="text-gray-200 font-medium">{{ $pending->user->name }}</div>
                        <div class="text-xs text-gray-500">{{ $pending->user->email }}</div>
                        <div class="text-xs text-gray-600">Current plan: {{ ucfirst($pending->user->plan) }}</div>
                    </td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $pending->plan === 'studio' ? 'bg-purple-500/20 text-purple-400' : 'bg-blue-500/20 text-blue-400' }}">
                            {{ ucfirst($pending->plan) }}
                        </span>
                    </td>
                    <td class="px-5 py-3">
                        @if($pending->status === 'pending')
                            <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs text-amber-400">Pending</span>
                        @elseif($pending->status === 'converted')
                            <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-400">Converted</span>
                        @elseif($pending->status === 'expired')
                            <span class="inline-flex items-center rounded-full bg-gray-500/20 px-2 py-0.5 text-xs text-gray-400">Expired</span>
                        @endif
                        @if($pending->notified_at)
                            <div class="text-xs text-gray-600 mt-1">Nudged {{ $pending->notified_at->diffForHumans() }}</div>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-gray-400 text-xs">
                        {{ $pending->created_at->format('M j, Y H:i') }}
                    </td>
                    <td class="px-5 py-3 text-xs">
                        @if($pending->expires_at)
                            @if($pending->expires_at->isPast())
                                <span class="text-red-400">{{ $pending->expires_at->diffForHumans() }}</span>
                            @else
                                <span class="text-gray-400">{{ $pending->expires_at->diffForHumans() }}</span>
                            @endif
                        @else
                            <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-400">
                        {{ $pending->affiliate_id ?? '—' }}
                    </td>
                    <td class="px-5 py-3">
                        @if($pending->status === 'pending')
                        <form method="POST" action="{{ route('super.pending-upgrades.manual-upgrade', $pending) }}" class="inline">
                            @csrf
                            <button type="submit"
                                    onclick="return exospaceConfirm(event, 'Manually upgrade {{ $pending->user->name }} to {{ ucfirst($pending->plan) }}? This bypasses 2Checkout payment verification.')"
                                    class="text-xs bg-purple-600 hover:bg-purple-500 text-white font-medium px-3 py-1.5 rounded-lg transition">
                                Manual Upgrade
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-5 py-12 text-center text-gray-500">No pending upgrades.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $pendingUpgrades->links() }}
    </div>
</div>
@endsection

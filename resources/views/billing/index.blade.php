<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Billing</h2>
    </x-slot>

<div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">

    {{-- Page header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-white">Billing</h1>
        <p class="text-sm text-gray-400 mt-1">Manage your subscription, view past invoices, and request refunds.</p>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-6 rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-4 py-3 text-sm text-emerald-300" role="status">{{ session('success') }}</div>
    @endif
    @if(session('info'))
        <div class="mb-6 rounded-lg bg-blue-500/10 border border-blue-500/30 px-4 py-3 text-sm text-blue-300" role="status">{{ session('info') }}</div>
    @endif
    @if(session('warning'))
        <div class="mb-6 rounded-lg bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-300" role="status">{{ session('warning') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-6 rounded-lg bg-red-500/10 border border-red-500/30 px-4 py-3 text-sm text-red-300" role="alert">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Current plan ─────────────────────────────────────────────── --}}
        <div class="lg:col-span-1">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Current Plan</h2>

                <div class="mb-4">
                    <div class="text-3xl font-bold text-white capitalize">{{ ucfirst($user->plan) }}</div>
                    @if($user->plan === 'free')
                        <p class="text-xs text-gray-500 mt-1">1 gallery · 10 images</p>
                    @elseif($user->plan === 'pro')
                        <p class="text-xs text-gray-500 mt-1">5 galleries · 100 images</p>
                    @elseif($user->plan === 'studio')
                        <p class="text-xs text-gray-500 mt-1">Unlimited galleries · 500 images</p>
                    @endif
                </div>

                <dl class="space-y-2 text-xs">
                    @if($user->plan_started_at)
                    <div>
                        <dt class="text-gray-500">Plan started</dt>
                        <dd class="text-gray-300">{{ $user->plan_started_at->format('M j, Y') }}</dd>
                    </div>
                    @endif

                    @if($user->plan_expires_at)
                    <div>
                        <dt class="text-gray-500">Plan expires</dt>
                        <dd class="text-gray-300 {{ $user->plan_expires_at->isPast() ? 'text-red-400' : '' }}">
                            {{ $user->plan_expires_at->format('M j, Y') }}
                            @if($user->plan_expires_at->isPast())
                                <span class="text-red-400">(expired)</span>
                            @elseif($user->plan_expires_at->diffInDays(now()) <= 7)
                                <span class="text-amber-400">({{ $user->plan_expires_at->diffInDays(now()) }} days left)</span>
                            @endif
                        </dd>
                    </div>
                    @elseif($user->plan !== 'free')
                    <div>
                        <dt class="text-gray-500">Plan type</dt>
                        <dd class="text-gray-300">Lifetime (one-time purchase)</dd>
                    </div>
                    @endif
                </dl>

                {{-- Upgrade CTAs --}}
                @if($user->plan === 'free')
                <div class="mt-6 space-y-2">
                    <a href="{{ route('billing.upgrade', 'pro') }}"
                       class="block w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-2.5 rounded-xl transition text-sm text-center">
                        Upgrade to Pro — $29
                    </a>
                    <a href="{{ route('billing.upgrade', 'studio') }}"
                       class="block w-full bg-gradient-to-r from-amber-500 to-red-500 hover:from-amber-400 hover:to-red-400 text-white font-bold py-2.5 rounded-xl transition text-sm text-center">
                        Upgrade to Studio — $99
                    </a>
                </div>
                @elseif($user->plan === 'pro')
                <div class="mt-6">
                    <a href="{{ route('billing.upgrade', 'studio') }}"
                       class="block w-full bg-gradient-to-r from-amber-500 to-red-500 hover:from-amber-400 hover:to-red-400 text-white font-bold py-2.5 rounded-xl transition text-sm text-center">
                        Upgrade to Studio — $99
                    </a>
                </div>
                @endif
            </div>
        </div>

        {{-- ── Transactions + pending upgrades ──────────────────────────── --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Pending upgrades (awaiting payment confirmation) --}}
            @if($pendingUpgrades->isNotEmpty())
            <div class="bg-amber-500/5 border border-amber-500/30 rounded-2xl p-6">
                <h2 class="text-sm font-semibold text-amber-400 uppercase tracking-wider mb-3">Pending Upgrades</h2>
                <p class="text-xs text-gray-400 mb-4">These are upgrade requests awaiting payment confirmation from 2Checkout. If you completed payment but your plan hasn't updated within 10 minutes, please contact support with your invoice ID.</p>

                <div class="space-y-2">
                    @foreach($pendingUpgrades as $pending)
                    <div class="flex items-center justify-between bg-gray-900/50 rounded-lg px-3 py-2 text-xs">
                        <div>
                            <span class="font-medium text-gray-200 capitalize">{{ $pending->plan }}</span>
                            <span class="text-gray-500 ml-2">started {{ $pending->created_at->diffForHumans() }}</span>
                        </div>
                        @if($pending->expires_at)
                        <span class="text-gray-500">expires {{ $pending->expires_at->diffForHumans() }}</span>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Transactions history --}}
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Transaction History</h2>

                @if($transactions->isEmpty())
                    <p class="text-sm text-gray-500 py-8 text-center">No transactions yet. Upgrade to Pro or Studio to unlock more galleries, images, and features.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                                    <th class="py-2 pr-4">Date</th>
                                    <th class="py-2 pr-4">Plan</th>
                                    <th class="py-2 pr-4">Amount</th>
                                    <th class="py-2 pr-4">Invoice ID</th>
                                    <th class="py-2 pr-4">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($transactions as $tx)
                                <tr class="border-b border-gray-800 last:border-0">
                                    <td class="py-3 pr-4 text-gray-300">{{ $tx->created_at->format('M j, Y') }}</td>
                                    <td class="py-3 pr-4 text-gray-300 capitalize">{{ $tx->plan }}</td>
                                    <td class="py-3 pr-4 text-gray-300">{{ $tx->formattedAmount() }}</td>
                                    <td class="py-3 pr-4 text-gray-500 font-mono text-xs">{{ $tx->invoice_id }}</td>
                                    <td class="py-3 pr-4">
                                        @if($tx->status === 'completed')
                                            <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-400">Completed</span>
                                        @elseif($tx->status === 'refunded')
                                            <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs text-amber-400">Refunded</span>
                                        @elseif($tx->status === 'chargeback')
                                            <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs text-red-400">Chargeback</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-500/20 px-2 py-0.5 text-xs text-gray-400">{{ ucfirst($tx->status) }}</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $transactions->links() }}
                    </div>
                @endif
            </div>

            {{-- Refund policy link --}}
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Need a Refund?</h2>
                <p class="text-sm text-gray-400 mb-3">We offer a 14-day money-back guarantee. To request a refund, please email <a href="mailto:support@exospace.gallery" class="text-purple-400 hover:text-purple-300 underline">support@exospace.gallery</a> with your invoice ID.</p>
                <a href="{{ route('refund') }}" class="text-sm text-purple-400 hover:text-purple-300 underline">Read our refund policy →</a>
            </div>

        </div>
    </div>
</div>
</x-app-layout>

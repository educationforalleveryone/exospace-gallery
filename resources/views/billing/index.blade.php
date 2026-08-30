<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Billing" description="Manage your subscription, view past invoices, and request refunds."/>
    </x-slot>

<div class="page-shell">


    {{-- ITERATION-3: flash banners removed — every flash key here is already
         announced by the layout's <x-toast>, so this page showed each message
         TWICE (banner + toast). The toast is the single transient-feedback
         channel; persistent plan-state context stays in the cards below. --}}

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
                        <p class="text-xs text-gray-500 mt-1">5 galleries · 100 images total</p>
                    @elseif($user->plan === 'studio')
                        <p class="text-xs text-gray-500 mt-1">Unlimited galleries · 500 images total</p>
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
                            @elseif(now()->diffInDays($user->plan_expires_at) <= 7)
                                <span class="text-amber-400">({{ now()->diffInDays($user->plan_expires_at) }} days left)</span>
                            @endif
                        </dd>
                    </div>
                    @elseif($user->plan !== 'free')
                    <div>
                        <dt class="text-gray-500">Plan type</dt>
                        <dd class="text-gray-300">Lifetime (one-time purchase)</dd>
                    </div>
                    @endif

                    {{-- M-1: Subscription status (only shown for recurring subscriptions) --}}
                    @if($user->hasSubscription())
                    <div>
                        <dt class="text-gray-500">Subscription</dt>
                        <dd>
                            @if($user->subscription_status === 'active')
                                <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-400">Active</span>
                                @if($user->subscription_ends_at)
                                    <span class="text-gray-400 text-xs ml-2">Renews {{ $user->subscription_ends_at->format('M j, Y') }}</span>
                                @endif
                            @elseif($user->subscription_status === 'past_due')
                                <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs text-amber-400">Past Due</span>
                                <span class="text-gray-400 text-xs ml-2">Payment failed — 2Checkout is retrying</span>
                            @elseif($user->subscription_status === 'cancelled')
                                <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs text-red-400">Cancelled</span>
                                @if($user->subscription_ends_at && $user->subscription_ends_at->isFuture())
                                    <span class="text-gray-400 text-xs ml-2">Access until {{ $user->subscription_ends_at->format('M j, Y') }}</span>
                                @else
                                    <span class="text-red-400 text-xs ml-2">Expired</span>
                                @endif
                            @else
                                <span class="text-gray-300">{{ ucfirst($user->subscription_status ?? 'unknown') }}</span>
                            @endif
                        </dd>
                    </div>
                    @endif
                </dl>

                {{-- M-1: Subscription management buttons --}}
                @if($user->hasActiveSubscription())
                <div class="mt-4">
                    <form action="{{ route('billing.cancel-subscription') }}" method="POST"
                          data-confirm="Cancel your subscription? You'll keep access until {{ $user->subscription_ends_at?->format('M j, Y') }}, then be downgraded to Free.">
                        @csrf
                        <button type="submit" class="btn btn-danger-ghost w-full">
                            Cancel Subscription
                        </button>
                    </form>
                </div>
                @elseif($user->canReactivateSubscription())
                <div class="mt-4">
                    <form action="{{ route('billing.reactivate-subscription') }}" method="POST"
                          data-busy data-busy-label="Reactivating…">
                        @csrf
                        <button type="submit" class="btn btn-primary w-full">
                            Reactivate Subscription
                        </button>
                    </form>
                    <p class="text-xs text-gray-500 mt-2 text-center">
                        Reactivate before {{ $user->subscription_ends_at?->format('M j, Y') }} to avoid losing access.
                    </p>
                </div>
                @endif

                {{-- Upgrade CTAs --}}
                @php
                    // M-1: Offer both one-time + recurring (subscription) options.
                    // ITERATION-5: moved ABOVE the plan branches — the pro→studio
                    // branch below also needs these vars (it previously lived
                    // inside the free branch only).
                    $recurringProPrice = config('services.2checkout.recurring_price_pro_monthly', '4.99');
                    $recurringStudioPrice = config('services.2checkout.recurring_price_studio_monthly', '14.99');
                    $hasRecurringPro = config('services.2checkout.recurring_product_id_pro');
                    $hasRecurringStudio = config('services.2checkout.recurring_product_id_studio');
                @endphp
                @if($user->plan === 'free')
                <div class="mt-6 space-y-2">
                    {{-- ITERATION-2 (trial wiring): the 14-day trial backend
                         (rate-limited, no card, one per user) existed since
                         2CO-8 but NOTHING linked to it. Offer it to eligible
                         free users right above the paid CTAs. --}}
                    @if(! $user->hasUsedTrial())
                    <div class="mb-3 rounded-xl border border-indigo-500/30 bg-indigo-950/30 px-4 py-3">
                        <p class="text-xs text-indigo-200 leading-relaxed">
                            Not ready to pay? <span class="font-semibold text-indigo-100">Try Pro free for 14 days</span> — every Pro feature, no card required.
                        </p>
                        <form action="{{ route('billing.start-trial', 'pro') }}" method="POST" class="mt-2.5"
                              data-busy data-busy-label="Starting trial…">
                            @csrf
                            <button type="submit" class="btn btn-secondary w-full">
                                Start 14-day Pro trial
                            </button>
                        </form>
                    </div>
                    @endif
                    {{-- M-1: Offer both one-time + recurring (subscription) options --}}
                    <a href="{{ route('billing.upgrade', 'pro') }}"
                       class="btn btn-primary w-full">
                        Pro — $29 one-time
                    </a>
                    @if($hasRecurringPro)
                    <a href="{{ route('billing.upgrade', 'pro') }}?recurring=1" class="btn btn-secondary w-full">
                        Pro — ${{ $recurringProPrice }}/month
                    </a>
                    @endif
                    <a href="{{ route('billing.upgrade', 'studio') }}" class="btn btn-primary w-full">
                        Studio — $99 one-time
                    </a>
                    @if($hasRecurringStudio)
                    <a href="{{ route('billing.upgrade', 'studio') }}?recurring=1" class="btn btn-secondary w-full">
                        Studio — ${{ $recurringStudioPrice }}/month
                    </a>
                    @endif
                </div>
                @elseif($user->plan === 'pro')
                <div class="mt-6 space-y-2">
                    <a href="{{ route('billing.upgrade', 'studio') }}" class="btn btn-primary w-full">
                        Upgrade to Studio — $99
                    </a>
                    @if($hasRecurringStudio)
                    {{-- ITERATION-5: monthly alternative — same parity the free
                         plan branch above already had. --}}
                    <a href="{{ route('billing.upgrade', 'studio') }}?recurring=1" class="btn btn-secondary w-full">
                        Studio — ${{ $recurringStudioPrice }}/month
                    </a>
                    @endif
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
                    <div class="empty-state">
                        <svg class="w-10 h-10 text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        <p class="text-gray-300 text-sm font-medium">No transactions yet</p>
                        <p class="text-gray-500 text-xs mt-1 max-w-xs">Purchases, renewals and refunds for this account will be listed here. Upgrade to Pro or Studio to unlock more galleries and images.</p>
                    </div>
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
                                    <th class="py-2 pr-4">Invoice</th>
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
                                    <td class="py-3 pr-4">
                                        @php
                                            // AUDIT-P0-1.6 FIX: Previously queried Invoice::where('transaction_id', $tx->id)->first()
                                            // inside this foreach — an N+1 query. Now eager-loaded in BillingController::index
                                            // via ->with('invoice'). Reads the loaded relationship directly.
                                            $invoice = $tx->invoice;
                                        @endphp
                                        @if($invoice && $invoice->pdf_path)
                                            <a href="{{ route('billing.invoice', $invoice) }}"
                                               class="inline-flex items-center gap-1 text-xs text-purple-400 hover:text-purple-300 transition"
                                               target="_blank" rel="noopener">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                                Download
                                            </a>
                                        @else
                                            <span class="text-xs text-gray-600">—</span>
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

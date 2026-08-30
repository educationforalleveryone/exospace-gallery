<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Delivery Ledger" :back="route('super.webhooks.index')" backLabel="Outbound webhooks"/>
    </x-slot>

    <div class="page-shell">
    <p class="text-sm text-gray-400 mb-6">
        One row per <code>OutboundWebhookService::dispatchSingle()</code> completion (success OR retry-exhausted). The retry loop runs
        internally (up to 3 attempts with 1+3+9s exponential backoff); the ledger captures the FINAL state, not one row per
        individual attempt. Use this page to triage "did the security team receive the <code>{{ $subscription->event_type }}</code>
        webhook last Tuesday?" — instead of greping rotated <code>laravel.log</code> files. Rows are pruned after the
        retention window (default 30 days — <code>OUTBOUND_WEBHOOK_LEDGER_RETENTION_DAYS</code>).
    </p>

    {{-- ── Subscription metadata tile ──────────────────────────────────── --}}
    <div class="card card-pad mb-8">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="modal-title">Subscription</h2>
            <div class="flex items-center gap-2">
                @if($subscription->is_active)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">active</span>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-500/15 text-gray-400 border border-gray-500/30">paused</span>
                @endif
                {{-- ITERATION-3: this was an <a data-method="PATCH"> whose
                     synthesizing script carried no nonce → CSP-blocked in
                     production → clicking navigated via GET → 405. It is a
                     real POST form now (same route, same confirm). --}}
                <form method="POST" action="{{ route('super.webhooks.toggle', $subscription) }}" class="inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" data-submit="exospaceConfirmWrapper"
                            data-confirm-message="{{ $subscription->is_active ? 'Pause' : 'Re-enable' }} this subscription?"
                            class="btn btn-sm btn-secondary">
                        {{ $subscription->is_active ? 'Pause' : 'Enable' }}
                    </button>
                </form>
            </div>
        </div>
        <div class="grid md:grid-cols-3 gap-3">
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Event type</div>
                <div class="text-sm text-gray-200 font-mono break-all">{{ $subscription->event_type }}</div>
            </div>
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Target URL</div>
                <div class="text-sm text-gray-200 font-mono break-all">{{ $subscription->target_url }}</div>
            </div>
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-xs text-gray-500 uppercase tracking-wider">HMAC secret</div>
                <div class="text-sm text-gray-200 font-mono">
                    @if($subscription->secret)
                        <span class="text-emerald-300">per-sub secret</span>
                    @else
                        <span class="text-gray-500">→ global (OUTBOUND_WEBHOOK_SECRET)</span>
                    @endif
                </div>
            </div>
        </div>
        @if($latest)
            <div class="mt-3 text-xs text-gray-500">
                <strong class="text-gray-400">Latest delivery:</strong>
                @if($latest->success)
                    <span class="text-emerald-300">✓ HTTP {{ $latest->http_status }}</span>
                @else
                    <span class="text-red-400">✗ {{ $latest->http_status ? 'HTTP ' . $latest->http_status : 'no response' }}</span>
                @endif
                <span class="text-gray-500"> · attempt {{ $latest->attempt_count }}/{{ \App\Services\OutboundWebhookService::MAX_RETRIES }}</span>
                <span class="text-gray-500"> · {{ $latest->delivered_at?->diffForHumans() }}</span>
            </div>
        @else
            <div class="mt-3 text-xs text-gray-500">
                <strong class="text-gray-400">Latest delivery:</strong> no deliveries recorded (the dispatch path hasn't fired for this
                subscription since the ledger was created — the env-URL subscriber path uses the same ledger, but this subscription's
                event hasn't dispatched yet).
            </div>
        @endif
    </div>

    {{-- ── Deliveries table ──────────────────────────────────────────────── --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
            <h2 class="modal-title">Delivery history</h2>
            <div class="text-xs text-gray-500">
                @if($deliveries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    {{ $deliveries->total() }} total · paginated 50/page · newest first
                @else
                    ledger table not yet migrated — run <code>php artisan migrate</code>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="table-base min-w-[860px] divide-y divide-gray-800">
            <thead class="bg-gray-800/50">
                <tr>
                    <th class="px-5 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Delivered</th>
                    <th class="px-5 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">HTTP</th>
                    <th class="px-5 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Attempts</th>
                    <th class="px-5 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($deliveries as $d)
                    <tr class="hover:bg-gray-800/30">
                        <td class="px-5 py-3 text-xs text-gray-300 font-mono">
                            {{ $d->delivered_at?->format('Y-m-d H:i:s T') }}
                            <div class="text-xs text-gray-500">{{ $d->delivered_at?->diffForHumans() }}</div>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            @if($d->success)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">success</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/15 text-red-300 border border-red-500/30">failed</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-100 font-mono">
                            @if($d->http_status !== null)
                                <span class="{{ $d->success ? 'text-emerald-300' : 'text-red-300' }}">{{ $d->http_status }}</span>
                            @else
                                <span class="text-gray-500" title="all retries threw exceptions / no response received">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-100 font-mono">
                            {{ $d->attempt_count }}/{{ \App\Services\OutboundWebhookService::MAX_RETRIES }}
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-400 font-mono break-all max-w-md">
                            @if($d->error_message)
                                <span title="{{ $d->error_message }}">{{ \Illuminate\Support\Str::limit($d->error_message, 120) }}</span>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-8 text-center text-gray-500">
                            No deliveries recorded for this subscription yet. The dispatch path will write a row here the
                            next time <code>{{ $subscription->event_type }}</code> fires.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($deliveries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $deliveries->hasPages())
            <div class="px-5 py-3 border-t border-gray-800">
                {{ $deliveries->links() }}
            </div>
        @endif
    </div>

    <div class="mt-6">
        <a href="{{ route('super.webhooks.index') }}" class="back-link">← Back to subscriptions</a>
    </div>
</div>

{{-- ITERATION-3: the @once PATCH-link synthesizer script carried no nonce —
     CSP blocked it in production, so the Pause/Enable link performed a raw
     GET → 405. Replaced by the real form above; the canonical
     exospaceConfirmWrapper (resources/js/app.js) provides the confirm. --}}
</x-app-layout>

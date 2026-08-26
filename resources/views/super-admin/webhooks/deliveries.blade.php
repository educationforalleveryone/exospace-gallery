<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Webhook delivery history</h2>
    </x-slot>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
    <nav class="text-xs text-gray-500 mb-2" aria-label="Breadcrumb">
        <a href="{{ route('super.webhooks.index') }}" class="hover:text-gray-300">Outbound webhooks</a>
        <span class="mx-1">/</span>
        <span class="text-gray-400">deliveries</span>
    </nav>

    <h1 class="text-2xl font-bold text-white mb-1">📨 Delivery ledger</h1>
    <p class="text-sm text-gray-400 mb-6">
        One row per <code>OutboundWebhookService::dispatchSingle()</code> completion (success OR retry-exhausted). The retry loop runs
        internally (up to 3 attempts with 1+3+9s exponential backoff); the ledger captures the FINAL state, not one row per
        individual attempt. Use this page to triage "did the security team receive the <code>{{ $subscription->event_type }}</code>
        webhook last Tuesday?" — instead of greping rotated <code>laravel.log</code> files. Rows are pruned after the
        retention window (default 30 days — <code>OUTBOUND_WEBHOOK_LEDGER_RETENTION_DAYS</code>).
    </p>

    {{-- ── Subscription metadata tile ──────────────────────────────────── --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-5 mb-8">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="text-lg font-bold text-white">Subscription</h2>
            <div class="flex items-center gap-2">
                @if($subscription->is_active)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">active</span>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-500/15 text-gray-400 border border-gray-500/30">paused</span>
                @endif
                <a href="{{ route('super.webhooks.toggle', $subscription) }}" data-method="PATCH" data-submit="exospaceConfirmLink"
                   data-confirm-message="{{ $subscription->is_active ? 'Pause' : 'Re-enable' }} this subscription?"
                   class="text-xs text-gray-400 hover:text-gray-200 underline">
                    {{ $subscription->is_active ? 'Pause' : 'Enable' }}
                </a>
            </div>
        </div>
        <div class="grid md:grid-cols-3 gap-3">
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">Event type</div>
                <div class="text-sm text-gray-200 font-mono break-all">{{ $subscription->event_type }}</div>
            </div>
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">Target URL</div>
                <div class="text-sm text-gray-200 font-mono break-all">{{ $subscription->target_url }}</div>
            </div>
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">HMAC secret</div>
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
    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
            <h2 class="text-lg font-bold text-white">Delivery history</h2>
            <div class="text-[11px] text-gray-500">
                @if($deliveries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    {{ $deliveries->total() }} total · paginated 50/page · newest first
                @else
                    ledger table not yet migrated — run <code>php artisan migrate</code>
                @endif
            </div>
        </div>
        <table class="min-w-full divide-y divide-gray-800">
            <thead class="bg-gray-800/50">
                <tr>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Delivered</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">HTTP</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Attempts</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($deliveries as $d)
                    <tr class="hover:bg-gray-800/30">
                        <td class="px-5 py-3 text-xs text-gray-300 font-mono">
                            {{ $d->delivered_at?->format('Y-m-d H:i:s T') }}
                            <div class="text-[10px] text-gray-500">{{ $d->delivered_at?->diffForHumans() }}</div>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            @if($d->success)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">success</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-red-500/15 text-red-300 border border-red-500/30">failed</span>
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
        @if($deliveries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $deliveries->hasPages())
            <div class="px-5 py-3 border-t border-gray-800">
                {{ $deliveries->links() }}
            </div>
        @endif
    </div>

    <div class="mt-6">
        <a href="{{ route('super.webhooks.index') }}" class="text-sm text-indigo-300 hover:text-indigo-200">← Back to subscriptions</a>
    </div>
</div>

@once
    <script>
        // CSP-safe delegated confirm wrapper for the toggle link (uses data-method + a link).
        // Same pattern as the form wrapper on the index page, but for hyperlinks.
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[data-submit="exospaceConfirmLink"]');
            if (!link) return;
            var msg = link.getAttribute('data-confirm-message') || 'Are you sure?';
            if (!window.confirm(msg)) { e.preventDefault(); return; }
            // Synthesize a POST form so PATCH/DELETE on a link works.
            e.preventDefault();
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = link.href;
            var csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = '{{ csrf_token() }}';
            var method = document.createElement('input'); method.type = 'hidden'; method.name = '_method'; method.value = link.getAttribute('data-method') || 'POST';
            form.appendChild(csrf); form.appendChild(method);
            document.body.appendChild(form);
            form.submit();
        });
    </script>
@endonce
</x-app-layout>

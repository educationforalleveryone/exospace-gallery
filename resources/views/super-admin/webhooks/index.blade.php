<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Outbound Webhooks</h2>
    </x-slot>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">📡 Outbound webhook subscriptions</h1>
    <p class="text-sm text-gray-400 mb-6">
        Per-event subscriptions for the <code>OutboundWebhookService</code> dispatch fan-out — a security team that only wants to subscribe to
        <code>billing.recipient_added</code> / <code>_removed</code> events no longer has to also receive every <code>gallery.published</code> /
        <code>user.upgraded</code> event the env-var subscriber receives. DB-backed, audit-logged add/remove + enable/disable (pause a noisy
        subscriber without losing its config). The env-var <code>OUTBOUND_WEBHOOK_URL</code> remains the always-on default subscription
        (every event it's configured for is dispatched to it, regardless of the DB list).
    </p>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-4 py-3 text-sm text-emerald-300" role="status">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="mb-4 rounded-lg bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-300" role="status">{{ session('warning') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-500/10 border border-red-500/30 px-4 py-3 text-sm text-red-300" role="alert">{{ session('error') }}</div>
    @endif

    {{-- ── Env-var state tile ─────────────────────────────────────────── --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-5 mb-8">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h2 class="text-lg font-bold text-white">Environment default</h2>
            <div class="text-[11px] text-gray-500">always-on · sync dispatch · HMAC-SHA256 signature · 3 retries (1+3+9s)</div>
        </div>
        <p class="text-xs text-gray-400 mb-3">
            The <code>OUTBOUND_WEBHOOK_URL</code> env var (configured in Coolify) is treated as a default subscription: every event the
            service dispatches is sent to it, regardless of the DB list below. A brand-new DB subscription for ONE event does NOT bypass
            the env subscriber for the OTHER events. To stop sending to the env URL, clear <code>OUTBOUND_WEBHOOK_URL</code> in Coolify.
        </p>
        <div class="grid md:grid-cols-2 gap-4">
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">OUTBOUND_WEBHOOK_URL</div>
                <div class="text-sm text-gray-200 font-mono break-all">
                    @if($envUrl)
                        {{ $envUrl }}
                    @else
                        <span class="text-gray-500">not configured (silent-skip)</span>
                    @endif
                </div>
            </div>
            <div class="bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">OUTBOUND_WEBHOOK_SECRET</div>
                <div class="text-sm text-gray-200 font-mono">
                    @if($envSecretSet)
                        <span class="text-emerald-300">configured (HMAC signing on)</span>
                    @else
                        <span class="text-amber-300">not configured (payloads dispatched unsigned)</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── Add subscription form ─────────────────────────────────────── --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-5 mb-8">
        <h2 class="text-lg font-bold text-white mb-3">Add a subscription</h2>
        <form method="POST" action="{{ route('super.webhooks.store') }}" class="space-y-3" x-data="{ custom: false }">
            @csrf
            <div class="grid md:grid-cols-3 gap-3">
                <div>
                    <label for="event_type" class="block text-xs text-gray-400 mb-1">Event type</label>
                    <select id="event_type" name="event_type" required
                            @change="custom = $event.target.value === '__custom__'"
                            class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        <option value="" disabled selected>— Select an event —</option>
                        @foreach($knownEvents as $ev)
                            <option value="{{ $ev }}">{{ $ev }}</option>
                        @endforeach
                        <option value="__custom__">— Other (custom event name) —</option>
                    </select>
                    @error('event_type') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="target_url" class="block text-xs text-gray-400 mb-1">Target URL (https://...)</label>
                    <input id="target_url" name="target_url" type="url" required placeholder="https://hooks.example.com/exospace"
                           value="{{ old('target_url') }}"
                           class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('target_url') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="secret" class="block text-xs text-gray-400 mb-1">
                        Per-subscription secret
                        <span class="text-gray-600">(optional — overrides OUTBOUND_WEBHOOK_SECRET)</span>
                    </label>
                    <input id="secret" name="secret" type="text" autocomplete="off" placeholder="leave empty to use global secret"
                           value="{{ old('secret') }}"
                           class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('secret') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <template x-if="custom">
                <div class="text-xs text-amber-300 bg-amber-500/10 border border-amber-500/30 rounded px-3 py-2">
                    Custom event names will only fire if a caller in the codebase dispatches them through
                    <code>OutboundWebhookService::dispatch()</code>. The documented events are the supported contract;
                    a custom event is for advanced use (e.g. an integration you're wiring in your own fork).
                </div>
            </template>
            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium bg-indigo-600 hover:bg-indigo-500 text-white">
                    Add subscription
                </button>
            </div>
        </form>
    </div>

    {{-- ── Per-event subscription count tiles (ITERATION 11) ─────────── --}}
    @php
        // Pivot the {event_type, is_active, count} rows into a per-event
        // shape: [event_type => ['active' => N, 'paused' => M]]. Done in
        // PHP instead of SQL so the rendering is one @foreach over
        // events + one @isset for each branch (no SQL CASE WHEN).
        $byEvent = [];
        foreach ($eventCounts as $row) {
            $et = $row->event_type;
            if (! isset($byEvent[$et])) {
                $byEvent[$et] = ['active' => 0, 'paused' => 0];
            }
            $byEvent[$et][$row->is_active ? 'active' : 'paused'] = $row->cnt;
        }
        ksort($byEvent);
    @endphp
    @if(! empty($byEvent))
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-5 mb-8">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="text-lg font-bold text-white">Per-event subscription counts</h2>
            <div class="text-[11px] text-gray-500">aggregated across all pages</div>
        </div>
        <p class="text-xs text-gray-400 mb-3">
            Quick triage surface — at a glance see which events have multiple subscribers (a noisy event the security team
            might be over-subscribed to) and which have paused subscribers (a subscriber that was disabled for incident
            triage without being deleted).
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($byEvent as $eventType => $counts)
                <div class="bg-gray-800/50 border border-gray-700/40 rounded-lg px-4 py-3">
                    <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1 break-all">{{ $eventType }}</div>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="text-emerald-300 font-mono">{{ $counts['active'] }} active</span>
                        @if($counts['paused'] > 0)
                            <span class="text-gray-500">·</span>
                            <span class="text-gray-400 font-mono">{{ $counts['paused'] }} paused</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Subscriptions list ────────────────────────────────────────── --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-800 flex items-center justify-between">
            <h2 class="text-lg font-bold text-white">Active subscriptions</h2>
            <div class="text-[11px] text-gray-500">
                @if($subscriptions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    {{ $subscriptions->total() }} total · paginated 25/page
                @else
                    subscriptions table not yet migrated
                @endif
            </div>
        </div>
        <table class="min-w-full divide-y divide-gray-800">
            <thead class="bg-gray-800/50">
                <tr>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Event</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Target URL</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Secret</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Last delivery</th>
                    <th class="px-5 py-2 text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Added</th>
                    <th class="px-5 py-2 text-right text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($subscriptions as $sub)
                    @php
                        // Look up the latest delivery for this subscription (already
                        // fetched in the controller via latestForSubscriptions() —
                        // one query for the page, not N+1).
                        $latest = $latestDeliveries->get($sub->id);
                    @endphp
                    <tr class="hover:bg-gray-800/30">
                        <td class="px-5 py-3 text-sm text-gray-100 font-mono">{{ $sub->event_type }}</td>
                        <td class="px-5 py-3 text-sm text-gray-200 font-mono break-all">{{ $sub->target_url }}</td>
                        <td class="px-5 py-3 text-xs">
                            @if($sub->secret)
                                <span class="text-emerald-300" title="per-subscription secret configured">per-sub HMAC</span>
                            @else
                                <span class="text-gray-500" title="falls back to global OUTBOUND_WEBHOOK_SECRET">→ global</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if($sub->is_active)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-500/15 text-gray-400 border border-gray-500/30">paused</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs">
                            @if($latest)
                                <a href="{{ route('super.webhooks.deliveries', $sub) }}" class="hover:underline" title="View delivery history">
                                    @if($latest->success)
                                        <span class="text-emerald-300">✓ HTTP {{ $latest->http_status }}</span>
                                    @else
                                        <span class="text-red-400">✗ {{ $latest->http_status ? 'HTTP ' . $latest->http_status : 'no response' }}</span>
                                    @endif
                                    <span class="text-gray-500"> · attempt {{ $latest->attempt_count }}/{{ \App\Services\OutboundWebhookService::MAX_RETRIES }}</span>
                                    <div class="text-[10px] text-gray-500">{{ $latest->delivered_at?->diffForHumans() }}</div>
                                </a>
                            @else
                                <span class="text-gray-600" title="no delivery recorded yet — the dispatch path hasn't fired for this subscription since the ledger was created">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-400">
                            {{ $sub->created_at?->diffForHumans() }}
                            @if($sub->addedBy)
                                <div class="text-[10px] text-gray-500">by {{ $sub->addedBy->name }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('super.webhooks.deliveries', $sub) }}"
                                   class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-gray-700 hover:bg-gray-600 text-white"
                                   title="View delivery history">History</a>
                                <form method="POST" action="{{ route('super.webhooks.toggle', $sub) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" data-submit="exospaceConfirmWrapper"
                                            data-confirm-message="{{ $sub->is_active ? 'Pause' : 'Re-enable' }} this subscription for {{ $sub->event_type }}?"
                                            class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium
                                                   {{ $sub->is_active ? 'bg-amber-600 hover:bg-amber-500 text-white' : 'bg-emerald-600 hover:bg-emerald-500 text-white' }}">
                                        {{ $sub->is_active ? 'Pause' : 'Enable' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('super.webhooks.destroy', $sub) }}" class="inline"
                                      data-submit="exospaceConfirmWrapper"
                                      data-confirm-message="Delete this subscription? {{ $sub->target_url }} will stop receiving {{ $sub->event_type }} events.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-red-600 hover:bg-red-500 text-white">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-gray-500">
                            No subscriptions yet. The env-var <code>OUTBOUND_WEBHOOK_URL</code> is the only active subscriber
                            @if($envUrl) ({{ $envUrl }}) @endif. Add a subscription above to fan out per-event.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if($subscriptions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $subscriptions->hasPages())
            <div class="px-5 py-3 border-t border-gray-800">
                {{ $subscriptions->links() }}
            </div>
        @endif
    </div>
</div>

@once
    <script>
        // CSP-safe delegated confirm wrapper (same pattern as Master Control + Billing Review).
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-submit="exospaceConfirmWrapper"]');
            if (!form) return;
            var msg = form.getAttribute('data-confirm-message') || 'Are you sure?';
            if (!window.confirm(msg)) { e.preventDefault(); }
        });
    </script>
@endonce
</x-app-layout>

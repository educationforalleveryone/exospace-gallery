<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Billing Review</h2>
    </x-slot>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">🧾 Billing Review</h1>
    <p class="text-sm text-gray-400 mb-6">
        Refunds, chargebacks and the 2Checkout webhook ledger — the three sources of truth (transactions, stored IPN payloads, admin audit records) joined in one place.
        Webhook payloads are retained 90 days. Replays run through the same idempotent pipeline as live ingress.
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

    {{-- ── 90-day snapshot ─────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-8">
        @php
            $tiles = [
                ['label' => 'Full refunds (90d)',   'value' => number_format($stats['refunds']),     'color' => 'text-red-400'],
                ['label' => 'Partial refunds (90d)','value' => number_format($stats['partial']),     'color' => 'text-amber-400'],
                ['label' => 'Chargebacks (90d)',    'value' => number_format($stats['chargebacks']), 'color' => 'text-orange-400'],
                ['label' => 'Failed webhooks',      'value' => number_format($stats['failed_webhooks']), 'color' => $stats['failed_webhooks'] > 0 ? 'text-red-300' : 'text-gray-400'],
                ['label' => 'Replayed webhooks',    'value' => number_format($stats['replayed']),    'color' => 'text-blue-400'],
                ['label' => 'Revenue (90d)',        'value' => '$' . number_format($stats['revenue_90d']), 'color' => 'text-emerald-400'],
            ];
        @endphp
        @foreach($tiles as $tile)
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-3 text-center">
                <div class="text-xl font-bold {{ $tile['color'] }}">{{ $tile['value'] }}</div>
                <div class="text-[11px] text-gray-500 mt-0.5">{{ $tile['label'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ITERATION 7 — weekly billing digest recipients. Managed here (DB-backed)
         rather than env-only so changes are attributable + survive deploys. The
         UI-managed list takes precedence over BILLING_EXPORT_EMAIL once any
         recipient is added. --}}
    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-5 mb-8">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h2 class="text-lg font-bold text-white">📬 Weekly billing digest recipients</h2>
            <div class="text-[11px] text-gray-500">scheduled Mondays 07:00 · audit-logged · recipients across deploys</div>
        </div>
        <p class="text-xs text-gray-400 mb-4">
            Every Monday a CSV of the previous week's money events + a weekly summary is emailed to the recipients below. The list is managed here once you add the first address — the <code>BILLING_EXPORT_EMAIL</code> env var remains the zero-deploy fallback for an empty list.
            @if(count($digestRecipients) > 0)
                <strong class="text-emerald-300">Active source: UI-managed list ({{ count($digestRecipients) }} recipient{{ count($digestRecipients) === 1 ? '' : 's' }}).</strong>
            @elseif(count($envDigestRecipients) > 0)
                <strong class="text-amber-300">Active source: BILLING_EXPORT_EMAIL fallback — adding any recipient here takes over.</strong>
            @else
                <strong class="text-gray-400">No recipients configured anywhere — the digest is effectively disabled (clean no-op + heartbeat stamp).</strong>
            @endif
        </p>

        <div class="grid md:grid-cols-2 gap-5">
            {{-- UI-managed recipients --}}
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">UI-managed (current source of truth)</div>
                @if(count($digestRecipients) > 0)
                    <ul class="space-y-1">
                        @foreach($digestRecipients as $recipient)
                            <li class="flex items-center justify-between bg-gray-800/50 border border-gray-700/40 rounded px-3 py-2" x-data="{ confirming: false }">
                                <div class="text-sm text-gray-200">
                                    {{ $recipient->email }}
                                    <div class="text-[10px] text-gray-500">added {{ $recipient->created_at?->diffForHumans() }}@if($recipient->addedBy) by {{ $recipient->addedBy->name }}@endif</div>
                                </div>
                                <div>
                                    <template x-if="!confirming">
                                        <button type="button" @click="confirming = true" class="text-xs text-red-400 hover:text-red-300">Remove</button>
                                    </template>
                                    <template x-if="confirming">
                                        <span class="flex items-center gap-2 text-xs">
                                            <span class="text-gray-300">Remove?</span>
                                            <form method="POST" action="{{ route('super.billing.recipients.destroy', $recipient) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-400 hover:text-red-300 font-semibold">Yes</button>
                                            </form>
                                            <button type="button" @click="confirming = false" class="text-gray-400 hover:text-gray-300">No</button>
                                        </span>
                                    </template>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="text-sm text-gray-500 py-4 text-center bg-gray-800/30 border border-dashed border-gray-700 rounded">
                        No UI-managed recipients yet.
                    </div>
                @endif

                <form method="POST" action="{{ route('super.billing.recipients.store') }}" class="mt-4 flex gap-2" data-submit="disableSubmitButton">
                    @csrf
                    <input type="email" name="email" required placeholder="recipient@example.com"
                           value="{{ old('email') }}"
                           class="flex-1 bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 outline-none" />
                    <button type="submit" class="px-4 py-2 rounded-md bg-emerald-600 hover:bg-emerald-500 text-white text-sm">Add</button>
                </form>
                @error('email')
                    <div class="mt-1 text-xs text-red-400">{{ $message }}</div>
                @enderror
            </div>

            {{-- Env fallback display (read-only) --}}
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">BILLING_EXPORT_EMAIL fallback (env var)</div>
                @if(count($envDigestRecipients) > 0)
                    <ul class="space-y-1">
                        @foreach($envDigestRecipients as $email)
                            <li class="bg-gray-800/30 border border-dashed border-gray-700 rounded px-3 py-2 text-sm text-gray-400">{{ $email }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-[10px] text-gray-500">Edit via the Coolify env var on the deployment config.</p>
                @else
                    <div class="text-sm text-gray-500 py-4 text-center bg-gray-800/30 border border-dashed border-gray-700 rounded">
                        Not configured.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Money events (transactions) ─────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h2 class="text-lg font-bold text-white">💳 Money events</h2>
        <div class="flex gap-1 text-xs items-center flex-wrap">
            @foreach(['' => 'Refunds & chargebacks', 'completed' => 'All purchases', 'refunded' => 'Full refunds', 'partial_refund' => 'Partial refunds', 'chargeback' => 'Chargebacks', 'manual' => 'Manual grants'] as $value => $label)
                <a href="{{ route('super.billing.index', array_filter(['status' => $value])) }}"
                   class="px-3 py-1.5 rounded-md {{ ($status ?? '') === $value || ($value === '' && !in_array($status, ['completed','refunded','partial_refund','chargeback','manual'], true)) ? 'bg-indigo-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
                    {{ $label }}
                </a>
            @endforeach
            {{-- ITERATION 5: CSV export — same status filter, 90-day window by
                 default (days=all for everything). Audit-logged. --}}
            <a href="{{ route('super.billing.export', array_filter(['export' => 'transactions', 'status' => $status ?? ''])) }}"
               class="px-3 py-1.5 rounded-md bg-emerald-600/80 hover:bg-emerald-500 text-white ml-1"
               title="Streamed CSV of the rows matching the current filter (90-day window)">
                ⬇ Transactions CSV
            </a>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden mb-10">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                    <th class="px-5 py-3">User</th>
                    <th class="px-5 py-3">Invoice</th>
                    <th class="px-5 py-3">Plan</th>
                    <th class="px-5 py-3">Amount</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $tx)
                <tr class="border-b border-gray-800 last:border-0 hover:bg-gray-800/30 transition">
                    <td class="px-5 py-3">
                        <div class="text-gray-200 font-medium">{{ $tx->user?->name ?? '—' }}</div>
                        <div class="text-xs text-gray-500">{{ $tx->user?->email ?? $tx->customer_email }}</div>
                    </td>
                    <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $tx->invoice_id }}</td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $tx->plan === 'studio' ? 'bg-purple-500/20 text-purple-400' : 'bg-blue-500/20 text-blue-400' }}">
                            {{ ucfirst($tx->plan) }}
                        </span>
                    </td>
                    <td class="px-5 py-3 text-gray-300">${{ number_format((float) $tx->amount, 2) }} <span class="text-xs text-gray-500">{{ $tx->currency }}</span></td>
                    <td class="px-5 py-3">
                        @if($tx->status === 'refunded')
                            <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-medium text-red-400">Refunded</span>
                        @elseif($tx->status === 'partial_refund')
                            <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-medium text-amber-400">Partial refund</span>
                        @elseif($tx->status === 'chargeback')
                            <span class="inline-flex items-center rounded-full bg-orange-500/20 px-2 py-0.5 text-xs font-medium text-orange-400">Chargeback</span>
                        @elseif($tx->status === 'manual')
                            <span class="inline-flex items-center rounded-full bg-blue-500/20 px-2 py-0.5 text-xs font-medium text-blue-400">Manual grant</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-medium text-emerald-400">Completed</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500">{{ $tx->created_at?->format('M j, Y g:ia') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No transactions with this status.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-800">
            {{ $transactions->links() }}
        </div>
    </div>

    {{-- ── Webhook ledger ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h2 class="text-lg font-bold text-white">📨 Webhook ledger</h2>
        <div class="flex gap-1 text-xs items-center">
            <a href="{{ route('super.billing.index', ['webhook_status' => 'failed']) }}"
               class="px-3 py-1.5 rounded-md {{ request('webhook_status') === 'failed' ? 'bg-red-600 text-white' : 'bg-gray-800 text-gray-400 hover:bg-gray-700' }}">
                Failed only
            </a>
            {{-- ITERATION 5: ledger CSV export — bounded by the 90-day payload
                 retention either way. Audit-logged. --}}
            <a href="{{ route('super.billing.export', array_filter(['export' => 'webhooks', 'webhook_status' => request('webhook_status')])) }}"
               class="px-3 py-1.5 rounded-md bg-emerald-600/80 hover:bg-emerald-500 text-white"
               title="Streamed CSV of the ledger rows (90-day window)">
                ⬇ Ledger CSV
            </a>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-700 rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-700">
                    <th class="px-5 py-3">Received</th>
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Invoice</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Replays</th>
                    <th class="px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($webhooks as $hook)
                <tr class="border-b border-gray-800 last:border-0 align-top">
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">
                        {{ $hook->processed_at?->format('M j, Y g:ia') ?? '—' }}
                        @if($hook->last_replayed_at)
                            <div class="text-[10px] text-blue-500">last replayed {{ $hook->last_replayed_at->diffForHumans() }}</div>
                        @endif
                    </td>
                    <td class="px-5 py-3 font-mono text-xs text-gray-300">{{ $hook->message_type }}</td>
                    <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ $hook->invoice_id ?? '—' }}</td>
                    <td class="px-5 py-3">
                        @if($hook->status === 'processed')
                            <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-medium text-emerald-400">Processed</span>
                        @elseif($hook->status === 'failed')
                            <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-medium text-red-400">Failed</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-medium text-amber-400">Processing</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-400">{{ $hook->replay_count }}</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            @if($hook->payload)
                                <details class="group">
                                    <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-200 select-none">▸ View payload</summary>
                                    <pre class="mt-2 p-3 bg-black/60 border border-gray-700 rounded-lg text-[10px] leading-relaxed text-gray-300 overflow-x-auto max-w-md">{{ json_encode($hook->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                <span class="text-[10px] text-gray-600">no payload stored</span>
                            @endif
                            <form method="POST" action="{{ route('super.billing.replay', $hook->id) }}"
                                  data-submit="exospaceConfirmWrapper"
                                  data-confirm-message="Replay {{ $hook->message_type }} (webhook #{{ $hook->id }}) through the billing pipeline? Handlers are idempotent, but a replay of a live event re-runs its side effects (emails, notifications).">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium
                                               {{ $hook->status === 'failed' ? 'bg-red-600 hover:bg-red-500 text-white' : 'bg-gray-700 hover:bg-gray-600 text-gray-200' }}">
                                    ↻ {{ $hook->status === 'failed' ? 'Retry via replay' : 'Replay' }}
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-gray-500">No webhooks recorded{{ request('webhook_status') === 'failed' ? ' with failed status' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-800">
            {{ $webhooks->links() }}
        </div>
    </div>
</div>

@once
    <script>
        // CSP-safe delegated confirm wrapper (same pattern as Master Control).
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-submit="exospaceConfirmWrapper"]');
            if (!form) return;
            var msg = form.getAttribute('data-confirm-message') || 'Are you sure?';
            if (!window.confirm(msg)) { e.preventDefault(); }
        });
    </script>
@endonce
</x-app-layout>

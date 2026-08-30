@extends('ops.layout')

@section('title', 'Access')

@section('content')
<div class="mb-4">
    <h1 class="text-xl font-semibold">Access</h1>
    <p class="text-xs text-slate-400 mt-1">
        Grant OpsCenter access to individual accounts — without giving them the keys to Master Control.
        <span class="text-cyan-300">Viewers</span> get read-only access (overview, applications, errors, incidents, diagnostic results);
        <span class="text-amber-300">Operators</span> additionally get to run the read-only diagnostics — allow-listed, audited checks against live subsystems.
        Lifecycle actions, the Actions hub, the credential inventory and this page stay super-admin-only, enforced at the route level.
    </p>
</div>

<div class="mb-6 rounded-lg border border-slate-800 bg-slate-900/40 px-4 py-3 text-xs text-slate-400 space-y-1">
    <p><span class="text-slate-300 font-medium">Policy for grantees:</span> verified email + MFA enabled (they are sent to MFA setup on first visit otherwise). One active grant per account; level changes revoke + re-grant in one step (the ledger keeps both rows); revocation is immediate.</p>
    <p><span class="text-slate-300 font-medium">Kill switches:</span> <span class="font-mono">OPS_VIEWER_ACCESS_ENABLED=false</span> fail-closes all viewer grants; <span class="font-mono">OPS_OPERATOR_ACCESS_ENABLED=false</span> all operator grants — instantly, without deleting them. Flip either back to restore.</p>
    <p>Every grant and revocation is audited (<span class="font-mono">ops.access.granted</span> / <span class="font-mono">ops.access.revoked</span>) and announced in the ops Slack channel.</p>
</div>

{{-- ── Grant form ───────────────────────────────────────────────────────── --}}
<section class="rounded-lg border border-slate-800 bg-slate-900/40 p-5 mb-6">
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Grant access</h2>
    @if($candidates->isEmpty())
        <p class="text-sm text-slate-500">No eligible accounts: every non-super-admin user either already holds an active grant or does not exist.</p>
    @else
        <form method="POST" action="{{ route('ops.access.grant') }}" class="flex flex-wrap items-start gap-3">
            @csrf
            <div class="flex-1 min-w-[260px]">
                <select name="user_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg text-sm text-slate-200 px-3 py-2.5 focus:border-emerald-600 outline-none">
                    @foreach($candidates as $candidate)
                        <option value="{{ $candidate->id }}">
                            {{ $candidate->name }} — {{ $candidate->email }}
                            @unless($candidate->email_verified_at) · email unverified @endunless
                            @if(empty($candidate->google2fa_secret)) · MFA not enabled @else · MFA on @endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[210px] pt-1">
                <div class="flex items-center gap-3 text-xs text-slate-300">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer" title="Read-only: everything visible, nothing executable">
                        <input type="radio" name="level" value="viewer" checked class="h-4 w-4 accent-cyan-500">
                        <span class="text-cyan-300 font-medium">Viewer</span>
                        <span class="text-slate-500">read-only</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer" title="Read + run the read-only diagnostics (audited)">
                        <input type="radio" name="level" value="operator" class="h-4 w-4 accent-amber-500">
                        <span class="text-amber-300 font-medium">Operator</span>
                        <span class="text-slate-500">+ run diagnostics</span>
                    </label>
                </div>
            </div>
            <button class="px-5 py-2.5 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-sm font-medium text-slate-50 transition">Grant access</button>
        </form>
    @endif
</section>

{{-- ── Active grants ───────────────────────────────────────────────────── --}}
<section class="mb-6">
    <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3">Active grants ({{ $activeGrants->count() }})</h2>
    <div class="overflow-x-auto rounded-lg border border-slate-800">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/80 text-slate-400 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Account</th>
                    <th class="text-left px-4 py-3">Level</th>
                    <th class="text-left px-4 py-3">Ready to enter?</th>
                    <th class="text-left px-4 py-3">Granted by</th>
                    <th class="text-left px-4 py-3">Granted</th>
                    <th class="text-right px-4 py-3">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/80">
                @forelse($activeGrants as $grant)
                    <tr class="hover:bg-slate-900/60">
                        <td class="px-4 py-3">
                            <div class="text-slate-100 font-medium">{{ $grant->user?->name ?? 'deleted account' }}</div>
                            <div class="text-xs text-slate-500">{{ $grant->user?->email ?? "user #{$grant->user_id}" }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($grant->level === 'operator')
                                <span class="text-xs px-2 py-1 rounded bg-amber-950/60 text-amber-300 border border-amber-800/50 uppercase font-bold" title="Read + run read-only diagnostics">{{ $grant->level }}</span>
                            @else
                                <span class="text-xs px-2 py-1 rounded bg-cyan-950/60 text-cyan-300 border border-cyan-800/50 uppercase font-bold" title="Read-only">{{ $grant->level }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if(!$grant->user)
                                <span class="text-xs px-2 py-1 rounded bg-slate-800 text-slate-400 border border-slate-600/50">ACCOUNT DELETED</span>
                            @elseif($grant->user->email_verified_at && !empty($grant->user->google2fa_secret))
                                <span class="text-xs px-2 py-1 rounded bg-emerald-950/60 text-emerald-300 border border-emerald-700/50 font-bold">YES</span>
                            @else
                                <span class="text-xs px-2 py-1 rounded bg-amber-950/60 text-amber-300 border border-amber-700/50 font-bold">
                                    {{ !$grant->user->email_verified_at ? 'EMAIL UNVERIFIED' : 'MFA SETUP NEEDED' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs">{{ $grant->granter?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500 text-xs">{{ $grant->granted_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                @if($grant->user)
                                    {{-- Level change — the atomic revoke+re-grant lives in OpsAccessService (one click, both ledger rows). --}}
                                    @if($grant->level === 'viewer')
                                        <form method="POST" action="{{ route('ops.access.grant') }}" data-confirm="Change this account to the OPERATOR tier? They will be able to run read-only diagnostics (audited) in addition to viewing." class="inline">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $grant->user_id }}">
                                            <input type="hidden" name="level" value="operator">
                                            <button class="text-xs px-3 py-1.5 rounded border border-amber-700/60 bg-amber-950/40 text-amber-300 hover:bg-amber-900/60 font-medium">Make operator…</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('ops.access.grant') }}" data-confirm="Downgrade this account to the VIEWER tier? They keep read access but can no longer run diagnostics." class="inline">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $grant->user_id }}">
                                            <input type="hidden" name="level" value="viewer">
                                            <button class="text-xs px-3 py-1.5 rounded border border-cyan-700/60 bg-cyan-950/40 text-cyan-300 hover:bg-cyan-900/60 font-medium">Make viewer…</button>
                                        </form>
                                    @endif
                                @endif
                                {{-- ITERATION-3: message moved into an attribute (was interpolated into a
                                     JS string — an apostrophe in the user's name broke the handler). --}}
                                <form method="POST" action="{{ route('ops.access.revoke', $grant) }}" data-confirm="Revoke OpsCenter access for {{ $grant->user?->name ?? 'this account' }}? Access ends immediately." class="inline">
                                    @csrf
                                    <button class="text-xs px-3 py-1.5 rounded border border-red-700/60 bg-red-950/40 text-red-300 hover:bg-red-900/60 font-medium">Revoke…</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500 text-sm">
                            No active grants — OpsCenter is super-admin only right now.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- ── Revoked history ──────────────────────────────────────────────────── --}}
<section>
    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">Revoked (history, most recent first)</h2>
    <div class="overflow-x-auto rounded-lg border border-slate-800/60">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/60 text-slate-500 text-xs uppercase tracking-wider">
                <tr>
                    <th class="text-left px-4 py-3">Account</th>
                    <th class="text-left px-4 py-3">Granted by</th>
                    <th class="text-left px-4 py-3">Granted</th>
                    <th class="text-left px-4 py-3">Revoked</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/40">
                @forelse($revokedGrants as $grant)
                    <tr class="text-slate-500">
                        <td class="px-4 py-2.5">{{ $grant->user?->name ?? 'deleted account' }} <span class="text-xs">({{ $grant->user?->email ?? "user #{$grant->user_id}" }})</span></td>
                        <td class="px-4 py-2.5 text-xs">{{ $grant->granter?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $grant->granted_at?->diffForHumans() }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $grant->revoked_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-slate-600 text-sm">No revocations yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

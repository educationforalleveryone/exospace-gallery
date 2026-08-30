{{--
    Operational status pill (ITERATION 2) — the shared four-state vocabulary
    for OpsCenter, Control Center and Master Control.

    HEALTHY (emerald) · WARNING (amber) · CRITICAL (red) · UNKNOWN (gray)
    plus INFO (blue) for running/neutral activity.

    Renders dot + word so state is never conveyed by color alone. Pairs with
    the .status-* classes in resources/css/app.css.

    Usage:
        <x-status-badge state="healthy" />                     → "● Healthy"
        <x-status-badge state="degraded" label="Degraded" />   → custom label
        <x-status-badge state="failed" :label="__('Failed')" dot="{{ false }}" />

    Aliases let each domain keep its own vocabulary (ok/passed, degraded/
    due_soon/flaky, failed/overdue/down, queued/pending/blocked …) while
    rendering one identical visual language.
--}}
@props([
    'state' => 'unknown',
    'label' => null,
    'dot'   => true,
])

@php
$statusMap = [
    // healthy
    'healthy'  => 'status-healthy',   'ok'     => 'status-healthy',
    'pass'     => 'status-healthy',   'passed' => 'status-healthy',
    'online'   => 'status-healthy',   'active' => 'status-healthy',
    'resolved' => 'status-healthy',   'operational' => 'status-healthy',
    'available'=> 'status-healthy',   'success' => 'status-healthy',
    'granted'  => 'status-healthy',

    // warning
    'warning'   => 'status-warning',  'warn'      => 'status-warning',
    'degraded'  => 'status-warning',  'due_soon'  => 'status-warning',
    'flaky'     => 'status-warning',  'stale'     => 'status-warning',
    'attention' => 'status-warning',  'inconclusive' => 'status-warning',
    'recently_broken' => 'status-warning', 'expiring' => 'status-warning',

    // critical
    'critical' => 'status-critical',  'crit'      => 'status-critical',
    'fail'     => 'status-critical',  'failed'    => 'status-critical',
    'error'    => 'status-critical',  'down'      => 'status-critical',
    'overdue'  => 'status-critical',  'banned'    => 'status-critical',
    'timed_out'=> 'status-critical',  'unavailable' => 'status-critical',
    'rotate_now' => 'status-critical', 'perma_fail' => 'status-critical',

    // info (running / neutral activity)
    'info'        => 'status-info',   'running'   => 'status-info',
    'in_progress' => 'status-info',   'testing'   => 'status-info',
    'acknowledged'=> 'status-info',   'deploying' => 'status-info',

    // unknown / neutral
    'unknown'      => 'status-unknown', 'queued'   => 'status-unknown',
    'pending'      => 'status-unknown', 'untracked'=> 'status-unknown',
    'inactive'     => 'status-unknown', 'neutral'  => 'status-unknown',
    'blocked'      => 'status-unknown', 'skipped'  => 'status-unknown',
    'cancelled'    => 'status-unknown', 'canceled' => 'status-unknown',
    'not_executed' => 'status-unknown', 'revoked'  => 'status-unknown',
    'off'          => 'status-unknown',
];
$statusClass = $statusMap[$state] ?? 'status-unknown';
@endphp

<span {{ $attributes->merge(['class' => 'status '.$statusClass]) }} role="status">
    @if($dot)<span class="status-dot" aria-hidden="true"></span>@endif
    {{ $label ?? str($state)->replace('_', ' ')->title() }}
</span>

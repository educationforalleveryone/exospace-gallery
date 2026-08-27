<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

/**
 * OpsCenter — SweepDiagnosticsCommand (Iteration 4; cadences in 6).
 *
 * ops:sweep-diagnostics — turns Iteration 3's PULL diagnostics into a PUSH
 * watch. Every 15 minutes (Coolify scheduled task) the command probes a
 * configured set of self-scoped, read-only diagnostics through
 * DiagnosticEngine::probe() and closes the loop autonomously:
 *
 *   degraded finding → ops_events row (source 'sweep', deduplicated by the
 *                      ingestor — recurrence bumps occurrence_count, never
 *                      spams) + Slack warning via OperationalAlertService
 *                      (dedup key ops.sweep.{id}, 2 h TTL)
 *   failed finding   → same, at error severity (dedup TTL 1 h)
 *   inconclusive     → nothing. "Cannot determine" is not a problem signal.
 *   healthy again    → the previously-recorded event is RESOLVED and an
 *                      info-level "recovered" note goes to Slack — the
 *                      story tells itself end to end.
 *
 * PER-CHECK CADENCE (Iteration 6): OPS_SWEEP_CADENCES throttles probing
 * per check while it is HEALTHY ("probe at most every N minutes") — so
 * expensive checks can run hourly while cheap ones stay at every sweep.
 * A check with an OPEN sweep event is always probed every sweep: recovery
 * detection is never delayed by a cadence, and a check that goes bad
 * between its scheduled probes is found at its next due probe (detection
 * latency = the cadence the operator chose). Skipped checks count as
 * 'skipped' in the summary; the last-probe timestamp lives in the cache
 * (ops:sweep:last:{id} — a cache flush just means one extra probe).
 *
 * Sweep events then flow through the normal machinery: classification,
 * correlation into incidents (ops:correlate-incidents), the dashboard,
 * the health score. A problem found by the sweep is indistinguishable
 * from one found by an operator — same pipeline, same record.
 *
 * Guarantees (mirroring every other OpsCenter entry point):
 *   - allow-listed: only registry diagnostics, only self-scoped ones
 *     (application-scoped checks need a target the sweep doesn't have).
 *   - read-only: probes never write; the only writes are the event row,
 *     its resolution, and the cache bookkeeping that remembers the last
 *     status per check.
 *   - never fatal: any per-check Throwable degrades to inconclusive; the
 *     command always exits 0 (a broken sweep must not break the schedule
 *     chain that runs after it).
 *   - kill switch: OPS_SWEEP_ENABLED=false exits immediately.
 */
class SweepDiagnosticsCommand extends Command
{
    protected $signature = 'ops:sweep-diagnostics';

    protected $description = 'Sweep the allow-listed self diagnostics; record deduplicated events + Slack alerts for degraded/failed findings, auto-resolve on recovery';

    /**
     * The minimum cadence a check may declare — the sweep itself runs
     * every 15 minutes, so anything finer is meaningless noise in the
     * config (warned + ignored, never fatal).
     */
    private const MIN_CADENCE_MINUTES = 15;

    /**
     * Sweep events get an explicit category so the error inventory groups
     * them under the right domain (forced categories trust the caller's
     * severity — deterministic, no pattern roulette).
     *
     * @var array<string, string>
     */
    private const EVENT_CATEGORIES = [
        // Explicit per-id mappings where the domain differs from the prefix.
        'app.cache' => 'REDIS',
        'app.filesystem' => 'STORAGE',
        'app.scheduler' => 'INFRASTRUCTURE',
        // Prefix fallbacks.
        'database' => 'DATABASE',
        'redis' => 'REDIS',
        'queue' => 'QUEUE',
        'server' => 'INFRASTRUCTURE',
        'app' => 'APPLICATION',
    ];

    public function handle(DiagnosticEngine $engine, OpsEventIngestor $ingestor): int
    {
        if (! config('ops.sweeps.enabled')) {
            $this->info('Sweeps disabled (OPS_SWEEP_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }

        $configured = (array) config('ops.sweeps.diagnostics', []);

        // Resolve the sweep set: registry-known, self-scoped ids only.
        $ids = [];
        foreach ($configured as $id) {
            if (! is_string($id) || trim($id) === '') {
                continue;
            }
            $id = trim($id);

            $definition = DiagnosticRegistry::get($id);
            if ($definition === null) {
                $this->warn("Skipping unknown diagnostic '{$id}' — not in the allow-list.");

                continue;
            }
            if ($definition['scope'] !== DiagnosticRegistry::SCOPE_SELF) {
                $this->warn("Skipping '{$id}' — only self-scoped diagnostics can be swept (application-scoped checks need a target).");

                continue;
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            $this->info('No sweepable diagnostics configured — nothing to do.');

            return self::SUCCESS;
        }

        // Resolve + validate the per-check cadences (Iteration 6).
        $cadences = $this->resolveCadences($ids);

        $counts = ['healthy' => 0, 'degraded' => 0, 'failed' => 0, 'inconclusive' => 0, 'recovered' => 0, 'skipped' => 0];

        foreach ($ids as $id) {
            // Cadence throttle (healthy path only — see shouldProbe()).
            if (! $this->shouldProbe($id, $cadences)) {
                $counts['skipped']++;
                $this->line(sprintf('[%s] skipped — cadence %d min not yet elapsed, no open event', $id, $cadences[$id]));

                continue;
            }

            try {
                $status = $this->sweepOne($id, $engine, $ingestor);
                $counts[$status]++;
            } catch (Throwable $e) {
                // The per-check guards below already handle their own
                // failures; this is the belt-and-braces net so the chain
                // never dies mid-sweep.
                $this->warn("Sweep of '{$id}' aborted unexpectedly: ".mb_substr($e->getMessage(), 0, 200));
                $counts['inconclusive']++;
            }
        }

        $summary = sprintf(
            'Sweep complete: %d healthy, %d degraded, %d failed, %d inconclusive, %d recovered, %d skipped (cadence)',
            $counts['healthy'],
            $counts['degraded'],
            $counts['failed'],
            $counts['inconclusive'],
            $counts['recovered'],
            $counts['skipped'],
        );

        ($counts['degraded'] + $counts['failed']) > 0
            ? $this->warn($summary)
            : $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * Validate the configured cadences against the sweep set: keep only
     * entries for known sweep ids with a sane value; warn (never fatal)
     * about anything else so a typo in OPS_SWEEP_CADENCES is visible in
     * scheduler.log without breaking the watch.
     *
     * @param  array<int, string>  $ids
     * @return array<string, int>
     */
    private function resolveCadences(array $ids): array
    {
        $configured = (array) config('ops.sweeps.cadences', []);
        $cadences = [];

        foreach ($configured as $id => $minutes) {
            $id = (string) $id;
            $minutes = (int) $minutes;

            if (! in_array($id, $ids, true)) {
                $this->warn("Ignoring cadence for '{$id}' — not in the sweep set (unknown id, not self-scoped, or not swept).");

                continue;
            }

            if ($minutes < self::MIN_CADENCE_MINUTES) {
                $this->warn("Ignoring cadence for '{$id}' — {$minutes} min is below the {$this->minCadenceLabel()} sweep interval.");

                continue;
            }

            $cadences[$id] = $minutes;
        }

        return $cadences;
    }

    private function minCadenceLabel(): string
    {
        return self::MIN_CADENCE_MINUTES.' min';
    }

    /**
     * Cadence decision for one check: probe it unless (a) it HAS a
     * cadence, (b) the last probe is more recent than that cadence, AND
     * (c) it currently has NO open sweep event. Condition (c) is the
     * safety valve: a check that is degraded/failed is always re-probed
     * every sweep so its recovery is detected within one sweep interval.
     *
     * @param  array<string, int>  $cadences
     */
    private function shouldProbe(string $id, array $cadences): bool
    {
        if (! isset($cadences[$id])) {
            return true; // no cadence → every sweep (Iteration-4 behavior)
        }

        if ($this->hasOpenEvent($id)) {
            return true; // active problem — always re-probe for recovery
        }

        try {
            $lastProbe = \Illuminate\Support\Facades\Cache::get('ops:sweep:last:'.$id);
        } catch (Throwable) {
            return true; // cache unavailable — probing is always safe
        }

        if (! $lastProbe instanceof \Illuminate\Support\Carbon) {
            return true; // never probed (or cache flushed) — due now
        }

        return $lastProbe->diffInMinutes(now()) >= $cadences[$id];
    }

    /**
     * Is there an OPEN/ACKNOWLEDGED sweep event for this check? Cheap:
     * the cached event id first, then a single indexed title lookup
     * (the same fallback resolvePriorEvent() uses after cache flushes).
     */
    private function hasOpenEvent(string $id): bool
    {
        $label = DiagnosticRegistry::label($id);
        $title = "Automated sweep: {$label}";

        try {
            return OpsEvent::query()
                ->where('source', 'sweep')
                ->whereIn('status', ['open', 'acknowledged'])
                ->where('title', $title)
                ->exists();
        } catch (Throwable) {
            return true; // DB trouble — probe anyway (probing is harmless)
        }
    }

    /**
     * Probe one diagnostic and process the outcome.
     *
     * @return string  the counted outcome: healthy|degraded|failed|inconclusive|recovered
     */
    private function sweepOne(string $id, DiagnosticEngine $engine, OpsEventIngestor $ingestor): string
    {
        $result = $engine->probe($id);

        if ($result === null) {
            $this->warn("Skipping unknown diagnostic '{$id}'.");

            return 'inconclusive';
        }

        // Cadence bookkeeping: this check was PROBED now (Iteration 6).
        try {
            \Illuminate\Support\Facades\Cache::put(
                'ops:sweep:last:'.$id,
                now(),
                now()->addDay(),
            );
        } catch (Throwable) {
            // Cache unavailable — the next sweep simply probes again.
        }

        // The finding line for scheduler.log / the console.
        $line = sprintf('[%s] %s — %s', $id, $result->status, $result->summary);

        if ($result->status === 'healthy') {
            // Recovery: ensure no lingering open sweep event for this
            // diagnostic. This runs on EVERY healthy probe — idempotent,
            // cheap, and self-healing even after a cache flush wiped the
            // last-status marker (the title fallback finds the event row
            // the cache forgot about). 'recovered' is only counted when a
            // row was actually resolved.
            if ($this->resolvePriorEvent($id)) {
                $this->line($line.' (recovered — event resolved)');
                $this->alertRecovery($id, $result->summary);

                return 'recovered';
            }

            $this->line($line);

            return 'healthy';
        }

        if ($result->status === 'inconclusive') {
            // Not a problem signal — record nothing, alert nothing.
            $this->line($line);

            return 'inconclusive';
        }

        // degraded | failed → record the deduplicated event + alert.
        $severity = $result->status === 'failed' ? 'error' : 'warning';
        $label = DiagnosticRegistry::label($id);

        $event = null;
        try {
            $event = $ingestor->record([
                'source' => 'sweep',
                'category' => $this->categoryFor($id),
                'severity' => $severity,
                'title' => "Automated sweep: {$label}",
                'message' => $result->summary.' — '.$result->interpretation,
                'context' => [
                    'sweep' => true,
                    'diagnostic' => $id,
                    'status' => $result->status,
                    'findings' => $this->notableFindings($result->findings),
                ],
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record sweep event: '.mb_substr($e->getMessage(), 0, 200));
        }

        if ($event !== null) {
            try {
                \Illuminate\Support\Facades\Cache::put(
                    'ops:sweep:event:'.$id,
                    $event->id,
                    now()->addDay(),
                );
            } catch (Throwable) {
                // Cache unavailable (Redis down?) — the event is still
                // recorded; recovery resolution will fall back to a
                // title lookup instead of the cached id.
            }
        }

        try {
            app(OperationalAlertService::class)->alert(
                "OpsCenter sweep: {$label} {$result->status}",
                $result->summary."\n".$this->notableFindingsText($result->findings),
                $severity,
                'ops.sweep.'.$id,
            );
        } catch (Throwable $e) {
            $this->warn('Could not send sweep alert: '.mb_substr($e->getMessage(), 0, 200));
        }

        $this->warn($line.($event !== null ? ' (event #'.$event->id.')' : ' (event recording failed)'));

        return $result->status;
    }

    /**
     * Resolve the sweep's prior OPEN/ACKNOWLEDGED event for this
     * diagnostic (cached id first, title fallback second). Returns true
     * only when a row was resolved RIGHT NOW — an already-resolved or
     * absent event returns false, so the recovery alert fires exactly
     * once per incident.
     */
    private function resolvePriorEvent(string $id): bool
    {
        $label = DiagnosticRegistry::label($id);
        $title = "Automated sweep: {$label}";
        $event = null;

        try {
            $cachedId = \Illuminate\Support\Facades\Cache::pull('ops:sweep:event:'.$id);
            if (is_numeric($cachedId) && (int) $cachedId > 0) {
                $event = OpsEvent::find((int) $cachedId);
            }

            // Fallback: the cached id may be gone (cache flush) while the
            // event row is still open — find it by its stable title.
            $event = $event
                ?? OpsEvent::query()
                    ->where('source', 'sweep')
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->where('title', $title)
                    ->latest('id')
                    ->first();

            if ($event === null || $event->status === 'resolved') {
                return false; // nothing to resolve (never recorded, already resolved, or resolved manually)
            }

            $event->status = 'resolved';
            $event->resolved_at = now();
            $event->save();

            return true;
        } catch (Throwable) {
            return false; // DB/cache trouble — the 7-day auto-resolve is the safety net
        }
    }

    private function alertRecovery(string $id, string $summary): void
    {
        $label = DiagnosticRegistry::label($id);

        try {
            app(OperationalAlertService::class)->alert(
                "OpsCenter sweep: {$label} recovered",
                "The check is healthy again: {$summary}. The sweep event has been resolved automatically.",
                'info',
                'ops.sweep.recovered.'.$id,
            );
        } catch (Throwable) {
            // Never fatal on the happy path.
        }
    }

    /**
     * The warn/fail findings, capped — the alert stays readable in Slack.
     *
     * @param  array<int, array{label: string, status: string, detail: string}>  $findings
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    private function notableFindings(array $findings): array
    {
        $notable = array_values(array_filter(
            $findings,
            fn ($f) => in_array($f['status'] ?? '', ['warn', 'fail'], true),
        ));

        return array_slice($notable, 0, 5);
    }

    /**
     * @param  array<int, array{label: string, status: string, detail: string}>  $findings
     */
    private function notableFindingsText(array $findings): string
    {
        $lines = [];
        foreach ($this->notableFindings($findings) as $finding) {
            $lines[] = '• '.mb_substr((string) $finding['detail'], 0, 220);
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /**
     * Deterministic category for a sweep event: exact per-id mappings win
     * (domains that differ from the prefix), then the prefix table.
     */
    private function categoryFor(string $id): string
    {
        if (isset(self::EVENT_CATEGORIES[$id])) {
            return self::EVENT_CATEGORIES[$id];
        }

        $prefix = explode('.', $id)[0];

        return self::EVENT_CATEGORIES[$prefix] ?? 'INFRASTRUCTURE';
    }
}

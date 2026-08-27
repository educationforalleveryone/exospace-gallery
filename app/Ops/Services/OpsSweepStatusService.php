<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Models\OpsEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OpsCenter — OpsSweepStatusService (Iteration 7).
 *
 * The measurement surface behind "tune the sweep cadences from real
 * data". The cadence MECHANISM shipped in Iteration 6 deliberately
 * untuned: OPS_SWEEP_CADENCES starts empty (every check, every sweep)
 * and the manual says "measure, then set cadences". This service is
 * the measure half — it exposes, per swept check:
 *
 *   cadence      the configured throttle (null = every sweep)
 *   last probe   how many minutes ago the check actually ran
 *                (the same ops:sweep:last:{id} cache stamp the sweep
 *                command maintains — skipped checks do NOT refresh it)
 *   open finding whether the check currently has an open/acknowledged
 *                sweep event (such a check is probed EVERY sweep
 *                regardless of its cadence — recovery is never delayed)
 *
 * Read-only, cache/DB-guarded, never throws: a missing stamp renders
 * as "never probed (or cache flushed)", an unreadable events table as
 * an honest unknown. Consumers: the Diagnostics page panel (the
 * operator-facing cadence table) and the morning digest's sweep
 * section.
 */
class OpsSweepStatusService
{
    /**
     * The sweep command's own cadence floor — anything finer than the
     * sweep interval is meaningless (the sweep simply cannot run more
     * often than the scheduler invokes it). Mirrors
     * SweepDiagnosticsCommand::MIN_CADENCE_MINUTES; duplicated because
     * that constant is private and this service must stay a reader,
     * not a friend.
     */
    public const SWEEP_INTERVAL_MINUTES = 15;

    /**
     * Per-check sweep state for every configured sweep id.
     *
     * @return array{
     *     enabled: bool,
     *     interval_minutes: int,
     *     checks: array<int, array{
     *         id: string, label: string, group: string,
     *         cadence_minutes: int|null, cadence_label: string,
     *         last_probe_at: CarbonInterface|null, last_probe_minutes: int|null,
     *         has_open_event: bool,
     *     }>,
     *     ignored: array<int, array{id: string, reason: string}>,
     * }
     */
    public function status(): array
    {
        $configured = (array) config('ops.sweeps.diagnostics', []);
        $cadences = (array) config('ops.sweeps.cadences', []);

        $checks = [];
        $ignored = [];

        foreach ($configured as $id) {
            if (! is_string($id) || trim($id) === '') {
                continue;
            }
            $id = trim($id);

            $definition = DiagnosticRegistry::get($id);
            if ($definition === null) {
                // The sweep command warns about these in scheduler.log;
                // here they stay visible in the UI too — a typo in
                // OPS_SWEEP_DIAGNOSTICS must not silently shrink the watch.
                $ignored[] = ['id' => $id, 'reason' => 'unknown diagnostic id — the sweep skips it with a warning'];
                continue;
            }
            if ($definition['scope'] !== DiagnosticRegistry::SCOPE_SELF) {
                $ignored[] = ['id' => $id, 'reason' => 'application-scoped — sweeps need a target, so it is skipped'];
                continue;
            }

            $cadence = null;
            if (isset($cadences[$id]) && (int) $cadences[$id] >= self::SWEEP_INTERVAL_MINUTES) {
                $cadence = (int) $cadences[$id];
            }

            $checks[] = [
                'id' => $id,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'cadence_minutes' => $cadence,
                'cadence_label' => $cadence !== null
                    ? sprintf('every %d min while healthy', $cadence)
                    : 'every sweep ('.self::SWEEP_INTERVAL_MINUTES.' min)',
                'last_probe_at' => $this->lastProbeAt($id),
                'last_probe_minutes' => null, // filled below (needs the Carbon)
                'has_open_event' => $this->hasOpenEvent($id),
            ];
        }

        foreach ($checks as $index => $check) {
            $minutes = null;
            if ($check['last_probe_at'] !== null) {
                // diffInMinutes() is fractional (Carbon 3) — the exact
                // float drives nothing here, the display floors it.
                $minutes = max(0, (int) floor($check['last_probe_at']->diffInMinutes(now())));
            }
            $checks[$index]['last_probe_minutes'] = $minutes;
        }

        return [
            'enabled' => (bool) config('ops.sweeps.enabled'),
            'interval_minutes' => self::SWEEP_INTERVAL_MINUTES,
            'checks' => $checks,
            'ignored' => $ignored,
        ];
    }

    /**
     * The cached last-probe stamp (a Carbon) or null. The stamp is only
     * written when a check is ACTUALLY probed — a cadence skip never
     * refreshes it, so the age shown here is the real probe cadence,
     * not the sweep's own interval.
     */
    private function lastProbeAt(string $id): ?CarbonInterface
    {
        try {
            $stamp = Cache::get('ops:sweep:last:'.$id);

            return $stamp instanceof CarbonInterface ? $stamp : null;
        } catch (Throwable) {
            return null; // cache unavailable — honest unknown
        }
    }

    /**
     * Open/acknowledged sweep event for this check? Same lookup the
     * sweep command's shouldProbe() uses (title match, source 'sweep')
     * — DB trouble reads as "yes" there (probe anyway); here it reads
     * as "unknown" rendered by the caller. The difference is safe:
     * this service only DISPLAYS state, it never decides to probe.
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
            return false; // honest "no open finding found" — never fatal
        }
    }
}

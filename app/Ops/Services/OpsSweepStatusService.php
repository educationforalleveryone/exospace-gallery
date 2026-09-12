<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Models\OpsEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

class OpsSweepStatusService
{
    public const SWEEP_INTERVAL_MINUTES = 15;

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

    private function lastProbeAt(string $id): ?CarbonInterface
    {
        try {
            $stamp = Cache::get('ops:sweep:last:'.$id);

            return $stamp instanceof CarbonInterface ? $stamp : null;
        } catch (Throwable) {
            return null; // cache unavailable — honest unknown
        }
    }

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

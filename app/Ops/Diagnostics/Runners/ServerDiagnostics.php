<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use Throwable;

/**
 * OpsCenter — ServerDiagnostics (Iteration 3).
 *
 * server.disk | server.resources
 *
 * server.disk measures the PERSISTENT VOLUME the application writes to
 * (storage path — uploads, logs, backups) with the same 80%/90% thresholds
 * OperationalAlertService already pages on, so the dashboard and Slack
 * agree on what "full" means.
 *
 * server.resources reports load, memory, uptime and runtime as seen from
 * INSIDE the container, labeled honestly: without lxcfs, /proc/meminfo
 * reflects the host, so figures are framed as "container view" — Coolify's
 * server view remains the host-authoritative source. No shellouts: every
 * number comes from PHP functions or /proc reads.
 *
 * Read-only by construction.
 */
class ServerDiagnostics implements RunsDiagnostics
{
    private const WARN_PCT = 80.0;

    private const CRIT_PCT = 90.0;

    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return match ($id) {
            'server.disk' => $this->disk(),
            'server.resources' => $this->resources(),
            default => DiagnosticResult::inconclusive(
                'Unknown server diagnostic',
                'This diagnostic id is not implemented by the server runner.',
            ),
        };
    }

    // ── server.disk ─────────────────────────────────────────────────────

    private function disk(): DiagnosticResult
    {
        $findings = [];
        $worstPct = null;

        // The persistent storage volume (uploads/logs/backups live here).
        foreach ($this->measurablePaths() as $label => $path) {
            try {
                $free = @disk_free_space($path);
                $total = @disk_total_space($path);

                if ($free === false || $total === false || $total <= 0) {
                    $findings[] = [
                        'label' => $label,
                        'status' => 'skip',
                        'detail' => 'Usage could not be measured for '.$path.'.',
                    ];

                    continue;
                }

                $usedPct = round((1 - $free / $total) * 100, 1);
                $freeGb = round($free / 1024 ** 3, 1);
                $totalGb = round($total / 1024 ** 3, 1);
                $worstPct = $worstPct === null ? $usedPct : max($worstPct, $usedPct);

                $status = $usedPct >= self::CRIT_PCT ? 'fail' : ($usedPct >= self::WARN_PCT ? 'warn' : 'pass');

                $findings[] = [
                    'label' => $label,
                    'status' => $status,
                    'detail' => sprintf('%.1f%% used — %.1f GB free of %.1f GB (%s).', $usedPct, $freeGb, $totalGb, $path),
                ];
            } catch (Throwable $e) {
                $findings[] = [
                    'label' => $label,
                    'status' => 'skip',
                    'detail' => 'Measurement failed: '.mb_substr($e->getMessage(), 0, 150),
                ];
            }
        }

        if ($worstPct === null) {
            return DiagnosticResult::inconclusive(
                'Disk usage could not be measured',
                'The filesystem statistics functions did not answer for the storage paths. This is unusual (the paths may be an exotic mount) — the alerting service\'s disk checks face the same paths, so its Slack messages are the fallback signal here.',
                ['server.resources'],
            );
        }

        return DiagnosticResult::fromFindings(
            sprintf('Disk %.1f%% used', $worstPct),
            $findings,
            $worstPct >= self::CRIT_PCT
                ? 'The volume is over '.self::CRIT_PCT.'% full. At this level Redis persistence fails first (MISCONF errors), then uploads and backups break, then everything else. Act now: the biggest occupants on this deployment are usually old backups, log files and uploaded images — the backup retention clean (backup:clean) and log rotation normally keep these in check; if they are piling up, run a manual clean via the deploy pipeline.'
                : ($worstPct >= self::WARN_PCT
                    ? 'The volume is over '.self::WARN_PCT.'% used. Nothing is failing yet, but plan cleanup before it crosses '.self::CRIT_PCT.'% — at that point Redis stops accepting writes and the failure mode becomes an outage. The alerting service also pages at these exact thresholds.'
                    : 'Disk usage is comfortably below the alerting thresholds (80% warning / 90% critical). No storage pressure.'),
            $worstPct >= self::WARN_PCT ? ['app.filesystem', 'queue.health'] : [],
        );
    }

    /**
     * @return array<string, string>
     */
    private function measurablePaths(): array
    {
        return [
            'Persistent storage volume (uploads, logs, backups)' => storage_path(),
            'Application root' => base_path(),
        ];
    }

    // ── server.resources ────────────────────────────────────────────────

    private function resources(): DiagnosticResult
    {
        $findings = [];

        // 1) Load average (container-scoped).
        try {
            $load = sys_getloadavg();

            if ($load !== false) {
                $cpus = $this->cpuCount();
                $perCpu = $cpus > 0 ? round($load[0] / $cpus, 2) : null;

                $findings[] = [
                    'label' => 'Load average (1 min)',
                    'status' => $perCpu !== null && $perCpu > 1.0 ? 'warn' : 'pass',
                    'detail' => sprintf(
                        '%.2f%s%s',
                        $load[0],
                        $perCpu !== null ? sprintf(' (%.2f per CPU core)', $perCpu) : '',
                        $perCpu !== null && $perCpu > 1.0 ? ' — sustained load above core count; requests and workers are queuing' : '',
                    ),
                ];
            }
        } catch (Throwable) {
            // sys_getloadavg unavailable — skip silently.
        }

        // 2) Memory (host-wide as seen from the container — honest label).
        $meminfo = $this->readMeminfo();

        if ($meminfo !== null) {
            $usedPct = $meminfo['total'] > 0
                ? round((1 - $meminfo['available'] / $meminfo['total']) * 100, 1)
                : null;

            $findings[] = [
                'label' => 'Memory (host view via /proc/meminfo)',
                'status' => ($usedPct ?? 0) >= 90 ? 'fail' : (($usedPct ?? 0) >= 80 ? 'warn' : 'pass'),
                'detail' => sprintf(
                    '%.1f%% used — %.1f GB available of %.1f GB. Note: from inside the container this reflects the HOST\'s memory; per-container limits are enforced by Docker/Coolify.',
                    $usedPct ?? 0,
                    $meminfo['available'] / 1024 ** 2,
                    $meminfo['total'] / 1024 ** 2,
                ),
            ];
        } else {
            $findings[] = [
                'label' => 'Memory (host view via /proc/meminfo)',
                'status' => 'skip',
                'detail' => '/proc/meminfo is not readable in this environment.',
            ];
        }

        // 3) Uptime.
        try {
            $uptimeSeconds = (int) (@file_get_contents('/proc/uptime') !== false
                ? (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0]
                : 0);

            if ($uptimeSeconds > 0) {
                $days = (int) floor($uptimeSeconds / 86400);
                $hours = (int) floor(($uptimeSeconds % 86400) / 3600);

                $findings[] = [
                    'label' => 'Host uptime',
                    'status' => 'pass',
                    'detail' => sprintf('Host booted %d day(s), %d hour(s) ago (kernel view). A very recent boot means the server restarted — worth knowing when diagnosing "everything broke at once".', $days, $hours),
                ];
            }
        } catch (Throwable) {
            // skip
        }

        // 4) PHP runtime + memory limit.
        $findings[] = [
            'label' => 'PHP runtime (control plane container)',
            'status' => 'pass',
            'detail' => sprintf(
                'PHP %s, memory limit %s, currently using %.1f MB. Container-level CPU/RAM limits and host-wide graphs live in Coolify\'s server view.',
                PHP_VERSION,
                ini_get('memory_limit') ?: 'unlimited',
                memory_get_usage(true) / 1024 ** 2,
            ),
        ];

        $anyFail = (bool) collect($findings)->first(fn ($f) => $f['status'] === 'fail');
        $anyWarn = (bool) collect($findings)->first(fn ($f) => $f['status'] === 'warn');

        return DiagnosticResult::fromFindings(
            $anyFail ? 'Resource pressure detected' : ($anyWarn ? 'Resource pressure building' : 'Server resources nominal'),
            $findings,
            $anyFail || $anyWarn
                ? 'At least one resource metric is above its comfort threshold. Memory pressure on the host eventually pushes the OOM killer into the container fleet (containers die with exit code 137 — the classifier flags those as CONTAINER events); sustained load above the core count means requests queue. Cross-check with the container events on the overview page: a wave of container restarts plus high memory is the classic host-pressure signature.'
                : 'Load, memory and uptime are in normal ranges from the container\'s point of view. These figures are the container/host view — Coolify\'s server page remains the host-authoritative resource view with graphs over time.',
            ['container.health', 'queue.health'],
        );
    }

    /**
     * @return array{total: int, available: int}|null Bytes.
     */
    private function readMeminfo(): ?array
    {
        try {
            $raw = @file_get_contents('/proc/meminfo');

            if ($raw === false) {
                return null;
            }

            $total = 0;
            $available = 0;

            foreach (explode("\n", $raw) as $line) {
                if (preg_match('/^MemTotal:\s+(\d+)\s+kB/i', $line, $m)) {
                    $total = (int) $m[1] * 1024;
                } elseif (preg_match('/^MemAvailable:\s+(\d+)\s+kB/i', $line, $m)) {
                    $available = (int) $m[1] * 1024;
                }
            }

            return $total > 0 ? ['total' => $total, 'available' => $available] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cpuCount(): int
    {
        // Container view of CPU count (sched_getaffinity — no shellout).
        try {
            $cpus = @sched_getaffinity();

            return $cpus !== false ? (is_countable($cpus) ? count($cpus) : 0) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}

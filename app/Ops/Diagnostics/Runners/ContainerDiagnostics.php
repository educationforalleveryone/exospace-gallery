<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\CoolifyApiClient;
use App\Ops\Support\LogRedactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — ContainerDiagnostics (Iteration 3).
 *
 * container.health | container.recent-logs
 *
 * container.health: live status from the Coolify API (NOT the 5-minute-old
 * sync row — a fresh call), interpreted (running:healthy vs exited vs
 * restarting = possible crash loop), plus the container events the control
 * plane has captured for that application.
 *
 * container.recent-logs: honest capability reporting. The Coolify REST API
 * does not expose container logs, so this diagnostic reports:
 *   - for the control plane host (self): a redacted tail of the actual log
 *     files on the persistent volume (laravel-*.log, scheduler.log);
 *   - for any other application: the errors/events the control plane has
 *     captured from it, and an explicit pointer to Coolify's log view.
 * It never pretends to have logs it cannot reach.
 *
 * Read-only: GET requests and file reads only.
 */
class ContainerDiagnostics implements RunsDiagnostics
{
    private const LOG_TAIL_LINES = 40;

    public function __construct(
        private readonly CoolifyApiClient $coolify,
        private readonly LogRedactor $redactor,
    ) {}

    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return match ($id) {
            'container.health' => $this->health($application),
            'container.recent-logs' => $this->recentLogs($application),
            default => DiagnosticResult::inconclusive(
                'Unknown container diagnostic',
                'This diagnostic id is not implemented by the container runner.',
            ),
        };
    }

    // ── container.health ────────────────────────────────────────────────

    private function health(?OpsApplication $application): DiagnosticResult
    {
        // Resolve the target: explicit application, else the control plane host.
        $target = $application ?? $this->selfApplication();
        $uuid = $this->coolifyUuid($target);

        if ($target === null || $uuid === null) {
            return DiagnosticResult::inconclusive(
                'No Coolify container for this target',
                'This application is not linked to a Coolify resource (no provider UUID). It may report through the ingestion API only, or the platform sync has not seen it yet. Its captured errors are still available via Recent errors, and it appears on the Applications page once Coolify reports it.',
                ['app.recent-errors'],
            );
        }

        $findings = [];
        $liveStatus = null;

        // 1) Live status from the Coolify API.
        try {
            $apps = $this->coolify->applications();
            $live = collect($apps)->first(fn ($row) => (string) ($row['uuid'] ?? '') === $uuid);

            if ($live !== null) {
                $liveStatus = strtolower((string) ($live['status'] ?? 'unknown'));

                $interpretation = $this->interpretStatus($liveStatus);

                $findings[] = [
                    'label' => 'Live Coolify status',
                    'status' => $this->statusToFinding($liveStatus),
                    'detail' => sprintf('%s — %s', $liveStatus, $interpretation),
                ];
            } else {
                $findings[] = [
                    'label' => 'Live Coolify status',
                    'status' => 'warn',
                    'detail' => 'The application no longer appears in the Coolify application list. It may have been deleted in Coolify, or the API token lost scope. The last synced status was "'.$target->status.'".',
                ];
            }
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Live Coolify status',
                'status' => 'skip',
                'detail' => 'Coolify API unreachable — showing the last synced status ("'.$target->status.'", '.($target->status_checked_at?->diffForHumans() ?? 'age unknown').'): '.mb_substr($e->getMessage(), 0, 150),
            ];
        }

        // 2) Sync age (how fresh is our baseline?).
        $findings[] = [
            'label' => 'Platform sync freshness',
            'status' => ($target->status_checked_at !== null && $target->status_checked_at->diffInMinutes(now()) > 15) ? 'warn' : 'pass',
            'detail' => sprintf('Last sync: %s (scheduled every 5 minutes).', $target->status_checked_at?->diffForHumans() ?? 'never'),
        ];

        // 3) Container events the control plane captured (restarts/degrades).
        $events = collect();
        try {
            $events = OpsEvent::query()
                ->where('ops_application_id', $target->id)
                ->whereIn('category', ['CONTAINER', 'DOCKER', 'INFRASTRUCTURE', 'DEPLOYMENT'])
                ->whereIn('status', ['open', 'acknowledged'])
                ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            // events table unavailable — findings below handle it.
        }

        $findings[] = $events->isEmpty()
            ? [
                'label' => 'Recent container events',
                'status' => 'pass',
                'detail' => 'No unresolved container/deployment events captured for this application.',
            ]
            : [
                'label' => 'Recent container events',
                'status' => $events->contains(fn ($e) => $e->severity === 'critical') ? 'fail' : 'warn',
                'detail' => sprintf(
                    '%d unresolved event(s): %s',
                    $events->count(),
                    $events->take(3)->pluck('title')->map(fn ($t) => '"'.mb_substr($t, 0, 80).'"')->implode(', '),
                ),
            ];

        $down = $liveStatus !== null && (str_starts_with($liveStatus, 'exited') || $liveStatus === 'stopped');

        return DiagnosticResult::fromFindings(
            $down
                ? ($target->name.' is NOT running')
                : ($liveStatus !== null && in_array($liveStatus, ['restarting', 'starting', 'degrading'], true)
                    ? $target->name.' is '.$liveStatus.' — possible crash loop'
                    : $target->name.' container '.$liveStatus),
            $findings,
            $this->healthInterpretation($target, $liveStatus, $events->isNotEmpty()),
            $events->isNotEmpty() ? ['container.recent-logs', 'deployment.recent'] : ['container.recent-logs'],
        );
    }

    private function healthInterpretation(OpsApplication $target, ?string $liveStatus, bool $hasEvents): string
    {
        if ($liveStatus === null) {
            return 'The live status could not be fetched from the Coolify API, so the last synced state and the captured events are the best available evidence. If the API itself is down, the platform sync event on the overview page says so.';
        }

        if (str_starts_with($liveStatus, 'running')) {
            if (str_contains($liveStatus, 'unhealthy')) {
                return 'The container IS running but its health check is failing — the process is up, yet the application inside is not answering as expected (a failed migration, a broken dependency, or a hung process produce exactly this). Run Recent logs next; for the control plane host, Application health gives the subsystem breakdown.';
            }

            return 'The container is running and reports healthy according to Coolify.'.$this->eventsNote($hasEvents);
        }

        if (in_array($liveStatus, ['restarting', 'starting', 'degrading'], true)) {
            return 'The container is cycling — Coolify reports "'.$liveStatus.'". A restart loop almost always means the process inside is exiting repeatedly: a config error, a failed migration at boot, or an OOM kill. Recent logs usually names the exact line it dies on; the error events below it show what the control plane captured.';
        }

        if (str_starts_with($liveStatus, 'exited') || $liveStatus === 'stopped') {
            return 'The container is NOT running — this application is effectively DOWN (or was deliberately stopped). The exit code is part of the status string (non-zero = crashed, 0 = stopped cleanly). Restarting is available as a controlled action from the Actions page; if it exits again immediately, the logs hold the reason.';
        }

        return 'Coolify reports status "'.$liveStatus.'" for this container.'.$this->eventsNote($hasEvents);
    }

    private function eventsNote(bool $hasEvents): string
    {
        return $hasEvents
            ? ' Note: the control plane HAS captured unresolved events for this application — running does not mean error-free (see the findings above).'
            : ' No unresolved container events are recorded for it.';
    }

    private function interpretStatus(string $status): string
    {
        return match (true) {
            $status === 'running:healthy' => 'running, health check passing',
            str_starts_with($status, 'running') => 'running (health state: '.(str_contains($status, 'unhealthy') ? 'FAILING' : 'unknown').')',
            in_array($status, ['restarting', 'starting', 'degrading'], true) => 'container cycling — possible crash loop',
            str_starts_with($status, 'exited') || $status === 'stopped' => 'container NOT running',
            default => 'unrecognized status',
        };
    }

    private function statusToFinding(string $status): string
    {
        return match (true) {
            $status === 'running:healthy' => 'pass',
            str_starts_with($status, 'running') => str_contains($status, 'unhealthy') ? 'warn' : 'pass',
            in_array($status, ['restarting', 'starting', 'degrading'], true) => 'fail',
            str_starts_with($status, 'exited') || $status === 'stopped' => 'fail',
            default => 'warn',
        };
    }

    // ── container.recent-logs ───────────────────────────────────────────

    private function recentLogs(?OpsApplication $application): DiagnosticResult
    {
        $target = $application ?? $this->selfApplication();

        // Self: we sit ON the logs — tail them (redacted).
        if ($target !== null && $target->is_self) {
            return $this->selfLogTail();
        }

        if ($target === null) {
            return DiagnosticResult::inconclusive(
                'No application context',
                'This diagnostic needs an application (or runs against the control plane host by default). No application could be resolved.',
                ['app.recent-errors'],
            );
        }

        // Another application: the control plane's captured view + an honest
        // pointer to Coolify's log view (the REST API does not expose logs).
        $events = OpsEvent::query()
            ->where('ops_application_id', $target->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->limit(10)
            ->get();

        $findings = [];

        $findings[] = [
            'label' => 'Captured errors (control plane)',
            'status' => $events->isEmpty() ? 'pass' : ($events->contains(fn ($e) => $e->severity === 'critical') ? 'fail' : 'warn'),
            'detail' => $events->isEmpty()
                ? 'No unresolved errors captured for this application.'
                : sprintf("%d active error(s): %s", $events->count(), $events->take(3)->pluck('title')->map(fn ($t) => '"'.mb_substr($t, 0, 90).'"')->implode('; ')),
        ];

        $baseUrl = rtrim((string) config('services.coolify.api_base_url', ''), '/');
        $findings[] = [
            'label' => 'Raw container logs',
            'status' => 'skip',
            'detail' => 'The Coolify REST API does not expose container logs, and this dashboard will not pretend otherwise. Open Coolify ('.($baseUrl !== '' ? $baseUrl : 'your Coolify URL').') → application "'.$target->name.'" → Logs for the live stream.',
        ];

        return DiagnosticResult::fromFindings(
            $events->isEmpty()
                ? 'No captured errors for '.$target->name
                : sprintf('%d active error(s) captured for %s', $events->count(), $target->name),
            $findings,
            $events->isEmpty()
                ? 'The control plane has captured no active errors from this application — either it is healthy, or it does not report through the ingestion API (only Coolify-side status changes are visible). For the live log stream use Coolify\'s own log view; this is deliberately not duplicated here.'
                : 'These are the errors this application has reported (or that the platform sync detected). They are the control-plane view; the full log stream with timestamps lives in Coolify\'s log view, which remains the authoritative source for raw logs. Coolify stays the deployment plane — this control plane aggregates, it does not replace.',
            ['container.health', 'app.recent-errors'],
        );
    }

    /**
     * Tail the control plane host's own log files (redacted, bounded).
     */
    private function selfLogTail(): DiagnosticResult
    {
        $findings = [];

        // 1) Latest laravel-*.log
        $laravelTail = $this->tailFile($this->latestLogPath('laravel-*.log'));
        $findings[] = [
            'label' => 'Application log (latest daily file)',
            'status' => $laravelTail === null ? 'skip' : ($laravelTail['lines'] === [] ? 'pass' : 'pass'),
            'detail' => $laravelTail === null
                ? 'No laravel-*.log file found in storage/logs yet.'
                : sprintf('%s — last %d line(s):%s', $laravelTail['file'], count($laravelTail['lines']), "\n".implode("\n", $laravelTail['lines'])),
        ];

        // 2) scheduler.log (the Coolify scheduled-task heartbeat).
        $schedulerTail = $this->tailFile(storage_path('logs/scheduler.log'));
        $schedulerAge = null;

        if (is_file(storage_path('logs/scheduler.log'))) {
            $ageMinutes = (time() - (int) filemtime(storage_path('logs/scheduler.log'))) / 60;
            $schedulerAge = $ageMinutes;

            $findings[] = [
                'label' => 'Scheduler log (Coolify scheduled task)',
                'status' => $ageMinutes > 10 ? 'fail' : 'pass',
                'detail' => sprintf('Last touched %.0f min ago.%s', $ageMinutes, $ageMinutes > 10 ? ' — STALE: the scheduled task is not running (cron stopped in Coolify?)' : '').($schedulerTail !== null ? "\n".implode("\n", array_slice($schedulerTail['lines'], -10)) : ''),
            ];
        } else {
            $findings[] = [
                'label' => 'Scheduler log (Coolify scheduled task)',
                'status' => 'skip',
                'detail' => 'No scheduler.log yet — the Coolify scheduled task ("php artisan schedule:run") has not written output since deploy.',
            ];
        }

        $staleScheduler = $schedulerAge !== null && $schedulerAge > 10;

        return DiagnosticResult::fromFindings(
            $staleScheduler ? 'Application logs reachable — scheduler.log is STALE' : 'Application logs reachable and fresh',
            $findings,
            $staleScheduler
                ? 'The application log files are readable (shown above, already redacted), but scheduler.log has not been touched in over 10 minutes. The scheduled task writes here every minute — a stale file means the Coolify scheduled task stopped (paused, or its cron broke). Background jobs, backups, digests and cleanup are NOT running while this is true. Check the scheduled task in Coolify (Scheduled Tasks → the schedule:run task) and its run history.'
                : 'The control plane host\'s own log files are readable and fresh. The tail above is the last '.self::LOG_TAIL_LINES.' line(s) of each, redacted through the same redactor every stored event passes through. Full history stays in the files (30-day rotation) and Sentry keeps the stack traces.',
            $staleScheduler ? ['app.scheduler', 'queue.health'] : ['app.recent-errors'],
        );
    }

    /**
     * @return array{file: string, lines: string[]}|null
     */
    private function tailFile(?string $path): ?array
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        try {
            $content = (string) file_get_contents($path, false, null, max(0, filesize($path) - 64 * 1024));
        } catch (Throwable $e) {
            Log::debug('ContainerDiagnostics: tail failed', ['error' => $e->getMessage()]);

            return null;
        }

        $lines = array_values(array_filter(explode("\n", trim($content)), fn ($l) => trim($l) !== ''));
        $tail = array_slice($lines, -self::LOG_TAIL_LINES);

        // Redact EVERY line through the same redactor the ingestor uses.
        $tail = array_map(
            fn ($line) => mb_substr($this->redactor->redactString($line), 0, 500),
            $tail,
        );

        return ['file' => basename($path), 'lines' => $tail];
    }

    /**
     * Newest file matching storage/logs/{pattern}.
     */
    private function latestLogPath(string $pattern): ?string
    {
        try {
            $files = glob(storage_path('logs/').$pattern) ?: [];

            if ($files === []) {
                return null;
            }

            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

            return $files[0];
        } catch (Throwable) {
            return null;
        }
    }

    private function selfApplication(): ?OpsApplication
    {
        try {
            return \App\Ops\Services\OpsEventIngestor::selfApplication();
        } catch (Throwable) {
            return null;
        }
    }

    private function coolifyUuid(?OpsApplication $application): ?string
    {
        if ($application === null) {
            return null;
        }

        $uuid = $application->provider === 'coolify' ? $application->provider_uuid : null;

        return $uuid !== null && $uuid !== '' ? $uuid : ($application->meta['coolify_uuid'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use Throwable;

class OpsHealthScoreService
{
    public const WEIGHTS = [
        'host' => 30,
        'applications' => 25,
        'untriaged' => 20,
        'incidents' => 15,
        'protection' => 10,
    ];

    public const APP_WEIGHTS = [
        'health' => 50,
        'untriaged' => 30,
        'incidents' => 20,
    ];

    public function __construct(
        private readonly OpsHealthService $health,
        private readonly OpsStatusTilesService $tiles,
    ) {}

    public function compute(array $input): array
    {
        $components = [
            'host' => $this->hostComponent((string) ($input['self_status'] ?? 'unknown'), (array) ($input['self_reasons'] ?? [])),
            'applications' => $this->applicationsComponent((array) ($input['applications'] ?? [])),
            'untriaged' => $this->untriagedComponent((array) ($input['untriaged_events'] ?? [])),
            'incidents' => $this->incidentsComponent((array) ($input['active_incidents'] ?? [])),
            'protection' => $this->protectionComponent(
                (array) ($input['backup_disks'] ?? []),
                (int) ($input['failed_webhooks'] ?? 0),
            ),
        ];

        $total = 0;
        foreach ($components as $key => $component) {
            $total += $component['score'] * $component['weight'];
        }

        $blend = (int) round($total / 100);

        $caps = $this->verdictCaps($input);

        $score = $blend;
        if ($caps !== []) {
            $score = min($blend, ...array_column($caps, 'limit'));
        }
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'band' => $score >= 90 ? 'healthy' : ($score >= 70 ? 'degraded' : 'critical'),
            'components' => $components,
            'applied_caps' => array_map(fn ($cap) => $cap['label'], $caps),
        ];
    }

    private function verdictCaps(array $input): array
    {
        $caps = [];

        $selfStatus = (string) ($input['self_status'] ?? 'unknown');
        if ($selfStatus === 'critical') {
            $caps[] = ['limit' => 60, 'label' => 'Host subsystems are DOWN (database or cache unreachable) — score capped at 60'];
        } elseif ($selfStatus === 'degraded') {
            $caps[] = ['limit' => 85, 'label' => 'Host subsystems degraded — score capped at 85'];
        }

        $apps = (array) ($input['applications'] ?? []);
        if ((int) ($apps['stopped'] ?? 0) > 0) {
            $caps[] = ['limit' => 65, 'label' => sprintf('%d application(s) stopped — score capped at 65', (int) $apps['stopped'])];
        } elseif ((int) ($apps['degraded'] ?? 0) > 0) {
            $caps[] = ['limit' => 85, 'label' => sprintf('%d application(s) degraded — score capped at 85', (int) $apps['degraded'])];
        }

        $disks = (array) ($input['backup_disks'] ?? []);
        $badDisks = (int) ($disks['stale'] ?? 0) + (int) ($disks['missing'] ?? 0);
        if ($badDisks > 0) {
            $caps[] = ['limit' => 65, 'label' => "{$badDisks} backup disk(s) stale or missing — score capped at 65"];
        }

        $untriaged = (array) ($input['untriaged_events'] ?? []);
        if ((int) ($untriaged['critical'] ?? 0) + (int) ($untriaged['error'] ?? 0) > 0) {
            $caps[] = ['limit' => 85, 'label' => 'Open untriaged critical/error event(s) — score capped at 85'];
        }

        $incidents = (array) ($input['active_incidents'] ?? []);
        if (array_sum(array_map('intval', $incidents)) > 0) {
            $caps[] = ['limit' => 85, 'label' => 'Active incident(s) under investigation — score capped at 85'];
        }

        return $caps;
    }

    public function computeLive(): array
    {
        $input = [];

        try {
            $self = $this->health->selfChecks();
            $input['self_status'] = $self['status'];
            $input['self_reasons'] = $self['reasons'];
        } catch (Throwable) {
            $input['self_status'] = 'unknown';
            $input['self_reasons'] = ['Host subsystem checks could not run'];
        }

        // ── Applications (non-server, same population as the overview) ─
        $apps = ['running' => 0, 'degraded' => 0, 'stopped' => 0, 'unknown' => 0];
        try {
            OpsApplication::whereNot('kind', 'server')
                ->selectRaw('health, COUNT(*) as n')
                ->groupBy('health')
                ->get()
                ->each(function ($row) use (&$apps) {
                    $key = (string) $row->health;
                    if (array_key_exists($key, $apps)) {
                        $apps[$key] = (int) $row->n;
                    } else {
                        $apps['unknown'] += (int) $row->n;
                    }
                });
        } catch (Throwable) {
            // Table absent — neutral counts flow through.
        }
        $input['applications'] = $apps;

        // ── Untriaged events vs active incidents (no double counting) ──
        $activeIds = [];
        try {
            $activeIds = OpsIncident::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->pluck('id')
                ->all();
        } catch (Throwable) {
            // Incidents table absent — every event counts.
        }

        $untriaged = ['critical' => 0, 'error' => 0, 'warning' => 0];
        try {
            $query = OpsEvent::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['critical', 'error', 'warning'])
                ->selectRaw('severity, COUNT(*) as n')
                ->groupBy('severity');

            if ($activeIds !== []) {
                $query->where(fn ($q) => $q
                    ->whereNull('ops_incident_id')
                    ->orWhereNotIn('ops_incident_id', $activeIds));
            }

            $query->get()->each(function ($row) use (&$untriaged) {
                $key = (string) $row->severity;
                if (array_key_exists($key, $untriaged)) {
                    $untriaged[$key] = (int) $row->n;
                }
            });
        } catch (Throwable) {
            // Events table absent — zero counts.
        }
        $input['untriaged_events'] = $untriaged;

        $incidents = ['critical' => 0, 'error' => 0, 'warning' => 0];
        try {
            OpsIncident::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->selectRaw('severity, COUNT(*) as n')
                ->groupBy('severity')
                ->get()
                ->each(function ($row) use (&$incidents) {
                    $key = (string) $row->severity;
                    if (array_key_exists($key, $incidents)) {
                        $incidents[$key] = (int) $row->n;
                    }
                });
        } catch (Throwable) {
            // Table absent.
        }
        $input['active_incidents'] = $incidents;

        $backupDisks = ['ok' => 0, 'stale' => 0, 'missing' => 0, 'unreadable' => 0];
        try {
            foreach ($this->tiles->backupStatus()['disks'] as $disk) {
                $key = (string) $disk['status'];
                $backupDisks[$key] = ($backupDisks[$key] ?? 0) + 1;
            }
        } catch (Throwable) {
            // Neutral "no disks" flows through.
        }
        $input['backup_disks'] = $backupDisks;

        try {
            $input['failed_webhooks'] = (int) $this->tiles->webhookStatus()['failed_count'];
        } catch (Throwable) {
            $input['failed_webhooks'] = 0;
        }

        return $this->compute($input);
    }

    // ── Per-application sub-score ────────────────────────────────────────

    public function computeApplication(array $input): array
    {
        $health = (string) ($input['health'] ?? 'unknown');
        $untriagedCounts = (array) ($input['untriaged_events'] ?? []);
        $incidentCounts = (array) ($input['active_incidents'] ?? []);

        $components = [
            'health' => $this->appHealthComponent($health),
            'untriaged' => $this->untriagedComponent($untriagedCounts, self::APP_WEIGHTS['untriaged']),
            'incidents' => $this->incidentsComponent($incidentCounts, self::APP_WEIGHTS['incidents']),
        ];

        $total = 0;
        foreach ($components as $component) {
            $total += $component['score'] * $component['weight'];
        }

        $blend = (int) round($total / 100);

        $caps = [];

        if ($health === 'stopped') {
            $caps[] = ['limit' => 65, 'label' => 'Application is stopped — sub-score capped at 65'];
        } elseif ($health === 'degraded') {
            $caps[] = ['limit' => 85, 'label' => 'Application is degraded — sub-score capped at 85'];
        }

        if ((int) ($untriagedCounts['critical'] ?? 0) + (int) ($untriagedCounts['error'] ?? 0) > 0) {
            $caps[] = ['limit' => 85, 'label' => 'Open untriaged critical/error event(s) — sub-score capped at 85'];
        }

        if (array_sum(array_map('intval', $incidentCounts)) > 0) {
            $caps[] = ['limit' => 85, 'label' => 'Active incident(s) for this application — sub-score capped at 85'];
        }

        $score = $blend;
        if ($caps !== []) {
            $score = min($blend, ...array_column($caps, 'limit'));
        }
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'band' => $score >= 90 ? 'healthy' : ($score >= 70 ? 'degraded' : 'critical'),
            'components' => $components,
            'applied_caps' => array_map(fn ($cap) => $cap['label'], $caps),
        ];
    }

    public function computeForApplications(iterable $applications): array
    {
        $ids = [];
        foreach ($applications as $application) {
            $ids[] = $application->id;
        }

        if ($ids === []) {
            return [];
        }

        $activeIds = [];
        try {
            $activeIds = OpsIncident::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->pluck('id')
                ->all();
        } catch (Throwable) {
            // Incidents table absent — every event counts.
        }

        // Untriaged events per app+severity.
        $events = [];
        try {
            $query = OpsEvent::query()
                ->whereIn('ops_application_id', $ids)
                ->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['critical', 'error', 'warning'])
                ->selectRaw('ops_application_id, severity, COUNT(*) as n')
                ->groupBy('ops_application_id', 'severity');

            if ($activeIds !== []) {
                $query->where(fn ($q) => $q
                    ->whereNull('ops_incident_id')
                    ->orWhereNotIn('ops_incident_id', $activeIds));
            }

            $query->get()->each(function ($row) use (&$events): void {
                $events[(int) $row->ops_application_id][(string) $row->severity] = (int) $row->n;
            });
        } catch (Throwable) {
            // Events table absent — zero counts.
        }

        // Active incidents per app+severity.
        $incidents = [];
        try {
            OpsIncident::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('ops_application_id', $ids)
                ->selectRaw('ops_application_id, severity, COUNT(*) as n')
                ->groupBy('ops_application_id', 'severity')
                ->get()
                ->each(function ($row) use (&$incidents): void {
                    $incidents[(int) $row->ops_application_id][(string) $row->severity] = (int) $row->n;
                });
        } catch (Throwable) {
            // Table absent.
        }

        $scores = [];
        foreach ($applications as $application) {
            $scores[$application->id] = $this->computeApplication([
                'health' => (string) $application->health,
                'untriaged_events' => $events[$application->id] ?? [],
                'active_incidents' => $incidents[$application->id] ?? [],
            ]);
        }

        return $scores;
    }

    private function appHealthComponent(string $health): array
    {
        $score = match ($health) {
            'running' => 100,
            'degraded' => 50,
            'stopped' => 0,
            default => 50,
        };

        $reasons = match ($health) {
            'running' => ['Application reports running:healthy'],
            'degraded' => ['Application reports a degraded state (unhealthy / restarting / starting)'],
            'stopped' => ['Application is stopped or exited'],
            default => ['No health data for this application (neutral 50)'],
        };

        return ['name' => 'Application health', 'score' => $score, 'weight' => self::APP_WEIGHTS['health'], 'reasons' => $reasons];
    }

    private function hostComponent(string $status, array $reasons): array
    {
        $score = match ($status) {
            'healthy' => 100,
            'degraded' => 50,
            'critical' => 0,
            default => 50,
        };

        $componentReasons = $score === 100
            ? ['All host subsystem checks passed (database, cache, queue, scheduler, backups, disk)']
            : ($reasons !== [] ? array_slice($reasons, 0, 3) : ["Host subsystems report '{$status}'"]);

        return ['name' => 'Host subsystems', 'score' => $score, 'weight' => self::WEIGHTS['host'], 'reasons' => $componentReasons];
    }

    private function applicationsComponent(array $apps): array
    {
        $total = array_sum(array_map('intval', $apps));

        if ($total === 0) {
            return [
                'name' => 'Applications',
                'score' => 50,
                'weight' => self::WEIGHTS['applications'],
                'reasons' => ['No applications synced from Coolify yet — neutral 50 until the first platform sync'],
            ];
        }

        $points = ($apps['running'] ?? 0) * 100
            + ($apps['degraded'] ?? 0) * 50
            + ($apps['stopped'] ?? 0) * 0
            + ($apps['unknown'] ?? 0) * 50;
        $score = (int) round($points / $total);

        $reasons = [];
        if (($apps['stopped'] ?? 0) > 0) {
            $reasons[] = sprintf('%d application(s) stopped', $apps['stopped']);
        }
        if (($apps['degraded'] ?? 0) > 0) {
            $reasons[] = sprintf('%d application(s) degraded', $apps['degraded']);
        }
        if (($apps['unknown'] ?? 0) > 0) {
            $reasons[] = sprintf('%d application(s) with no health data', $apps['unknown']);
        }
        if ($reasons === []) {
            $reasons[] = sprintf('%d of %d application(s) running healthy', $apps['running'] ?? 0, $total);
        }

        return ['name' => 'Applications', 'score' => $score, 'weight' => self::WEIGHTS['applications'], 'reasons' => $reasons];
    }

    private function untriagedComponent(array $counts, ?int $weight = null): array
    {
        $critical = (int) ($counts['critical'] ?? 0);
        $error = (int) ($counts['error'] ?? 0);
        $warning = (int) ($counts['warning'] ?? 0);

        $penalty = 25 * $critical + 10 * $error + 3 * $warning;
        $score = max(0, 100 - $penalty);

        $reasons = [];
        if ($critical > 0) {
            $reasons[] = "{$critical} untriaged critical event(s)";
        }
        if ($error > 0) {
            $reasons[] = "{$error} untriaged error(s)";
        }
        if ($warning > 0) {
            $reasons[] = "{$warning} untriaged warning(s)";
        }
        if ($reasons === []) {
            $reasons[] = 'No open untriaged error events';
        } else {
            $reasons[] = 'excludes events already tracked inside active incidents';
        }

        return ['name' => 'Untriaged errors', 'score' => $score, 'weight' => $weight ?? self::WEIGHTS['untriaged'], 'reasons' => $reasons];
    }

    private function incidentsComponent(array $counts, ?int $weight = null): array
    {
        $critical = (int) ($counts['critical'] ?? 0);
        $error = (int) ($counts['error'] ?? 0);
        $warning = (int) ($counts['warning'] ?? 0);

        $penalty = 30 * $critical + 15 * $error + 6 * $warning;
        $score = max(0, 100 - $penalty);

        $reasons = [];
        if ($critical > 0) {
            $reasons[] = "{$critical} active critical incident(s)";
        }
        if ($error > 0) {
            $reasons[] = "{$error} active error incident(s)";
        }
        if ($warning > 0) {
            $reasons[] = "{$warning} active warning incident(s)";
        }
        if ($reasons === []) {
            $reasons[] = 'No active incidents';
        }

        return ['name' => 'Active incidents', 'score' => $score, 'weight' => $weight ?? self::WEIGHTS['incidents'], 'reasons' => $reasons];
    }

    private function protectionComponent(array $disks, int $failedWebhooks): array
    {
        // Backup part (70 % of the component).
        $diskTotal = array_sum(array_map('intval', $disks));
        if ($diskTotal === 0) {
            $backupScore = 50;
            $backupReasons = ['No backup disks configured'];
        } else {
            $points = ($disks['ok'] ?? 0) * 100
                + ($disks['stale'] ?? 0) * 0
                + ($disks['missing'] ?? 0) * 0
                + ($disks['unreadable'] ?? 0) * 50;
            $backupScore = (int) round($points / $diskTotal);

            $backupReasons = [];
            if (($disks['missing'] ?? 0) > 0) {
                $backupReasons[] = sprintf('%d disk(s) with NO backup archive', $disks['missing']);
            }
            if (($disks['stale'] ?? 0) > 0) {
                $backupReasons[] = sprintf('%d disk(s) with stale backups (>26 h)', $disks['stale']);
            }
            if (($disks['unreadable'] ?? 0) > 0) {
                $backupReasons[] = sprintf('%d disk(s) unreadable', $disks['unreadable']);
            }
            if ($backupReasons === []) {
                $backupReasons[] = sprintf('%d disk(s) with fresh backups', $disks['ok'] ?? 0);
            }
        }

        // Webhook part (30 % of the component).
        if ($failedWebhooks === 0) {
            $webhookScore = 100;
            $webhookReasons = ['webhook ledger clean'];
        } elseif ($failedWebhooks > 5) {
            $webhookScore = 0;
            $webhookReasons = ["{$failedWebhooks} failed webhooks — replay needed"];
        } else {
            $webhookScore = 50;
            $webhookReasons = ["{$failedWebhooks} failed webhook(s) awaiting replay"];
        }

        $score = (int) round(0.7 * $backupScore + 0.3 * $webhookScore);

        return [
            'name' => 'Data protection',
            'score' => $score,
            'weight' => self::WEIGHTS['protection'],
            'reasons' => array_merge(
                array_map(fn ($r) => 'Backups: '.$r, $backupReasons),
                array_map(fn ($r) => 'Webhooks: '.$r, $webhookReasons),
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use Throwable;

/**
 * OpsCenter — OpsHealthScoreService (Iteration 4).
 *
 * The single 0–100 platform health score shown on the overview. The brief
 * was explicit: no meaningless numbers. So the score is a documented,
 * weighted blend of five components — each 0–100, each carrying its own
 * reasons — plus VERDICT CAPS that keep the number from ever being rosier
 * than the status label beside it. The formula lives HERE, in one
 * auditable place (mirrored in docs/MASTER_MANUAL_OPERATIONS.md §16):
 *
 *   Host subsystems ..... 30 %   OpsHealthService::selfChecks() rollup
 *                                  (DB, cache, queue, scheduler, backups,
 *                                  disk) — healthy 100 / degraded 50 /
 *                                  critical 0.
 *   Applications ........ 25 %   Average across non-server apps from the
 *                                  Coolify sync — running 100 / degraded
 *                                  50 / stopped 0 / unknown 50. No apps
 *                                  synced yet → 50 (honest neutral, with
 *                                  a reason).
 *   Untriaged errors .... 20 %   100 − (25×critical + 10×error + 3×warning)
 *                                  over OPEN/ACKNOWLEDGED events NOT
 *                                  already inside an active incident.
 *                                  Deliberately: an error that is part of
 *                                  an incident is counted once — as part
 *                                  of that incident — never twice.
 *   Active incidents .... 15 %   100 − (30×critical + 15×error + 6×warning)
 *                                  over OPEN/ACKNOWLEDGED incidents.
 *   Data protection ..... 10 %   70 % backup freshness (per-disk average:
 *                                  fresh 100 / stale 0 / missing 0 /
 *                                  unreadable 50) + 30 % webhook ledger
 *                                  (0 failed 100 / 1–5 failed 50 / >5 0).
 *
 *   score = min( blend, caps… )   blend = Σ weight_i × component_i / 100
 *
 * VERDICT CAPS (the anti-rose-colored-glasses rule). The status label on
 * the dashboard is worst-of; the blend averages. Averages can talk you
 * out of a verdict the platform has already rendered — the caps stop
 * that. Each cap mirrors a condition OpsHealthService::platformHealth()
 * treats as critical/degraded, so the score can NEVER disagree upward:
 *
 *   Host subsystems critical (database or cache down)  → cap 60
 *   Any application stopped                             → cap 65
 *   Any backup disk missing or stale (>26 h)            → cap 65
 *   Host degraded · any app degraded · any open untriaged
 *   critical/error event · any active incident          → cap 85
 *
 * Reading the two together is diagnostic, not redundant: label CRITICAL
 * with a high-ish score (capped) = ONE serious localized problem; label
 * DEGRADED with a LOW score = many small problems compounding. The
 * breakdown card always shows which caps applied and why.
 *
 * Bands: 90–100 HEALTHY · 70–89 DEGRADED · below 70 CRITICAL.
 *
 * compute() is a PURE function over an input array (unit-tested to
 * exhaustion); computeLive() is the thin aggregator that gathers the
 * inputs from the existing read paths (ADR-6 — no new monitors, no new
 * tables, nothing persisted; the score is always derivable, so it is
 * always current by construction).
 *
 * ── Iteration 5: the per-application sub-score (§16.2 of the manual) ──
 *
 * computeApplication() applies the SAME philosophy — weighted
 * components, reasons, verdict caps, bands — scoped to ONE application,
 * so each row on the Applications page answers "is THIS app healthy?"
 * while the platform score answers "is the PLATFORM healthy?".
 *
 *   Application health   50 %   running 100 / degraded 50 / stopped 0 /
 *                               unknown 50 (same mapping as the platform
 *                               applications component, so the two never
 *                               disagree about what "degraded" is worth)
 *   Untriaged errors     30 %   SAME penalties as the platform component
 *                               (100 − 25×critical − 10×error − 3×warning)
 *                               over the APP's open events not already in
 *                               an active incident
 *   Active incidents     20 %   SAME penalties as the platform component
 *                               over the APP's open incidents
 *
 *   Caps (mirroring the platform caps, app-scoped):
 *     app stopped                       → cap 65
 *     app degraded                      → cap 85
 *     any untriaged critical/error      → cap 85
 *     any active incident               → cap 85
 *
 * Host subsystems and data protection are DELIBERATELY excluded: they
 * are platform-wide facts already expressed in the platform score —
 * copying them into every row would make a single host problem look
 * like four app problems. Same bands (90 / 70) as the platform score.
 */
class OpsHealthScoreService
{
    /**
     * Component weights — must sum to exactly 100 (enforced by test).
     */
    public const WEIGHTS = [
        'host' => 30,
        'applications' => 25,
        'untriaged' => 20,
        'incidents' => 15,
        'protection' => 10,
    ];

    /**
     * Per-application component weights — must sum to exactly 100
     * (enforced by test). See the class docblock (§16.2).
     */
    public const APP_WEIGHTS = [
        'health' => 50,
        'untriaged' => 30,
        'incidents' => 20,
    ];

    public function __construct(
        private readonly OpsHealthService $health,
        private readonly OpsStatusTilesService $tiles,
    ) {}

    /**
     * Pure computation. @see computeLive() for the input shape.
     *
     * @return array{
     *     score: int, band: string,
     *     components: array<string, array{name: string, score: int, weight: int, reasons: string[]}>,
     *     applied_caps: array<int, string>
     * }
     */
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

        // Verdict caps — see the class docblock. Each mirrors a condition
        // the status label already treats as critical or degraded.
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

    /**
     * The caps in force for this input (empty when the platform has no
     * verdict-level problems — the blend then speaks freely).
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array{limit: int, label: string}>
     */
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

    /**
     * Gather live inputs and compute. Never throws — an unavailable input
     * degrades to the honest-neutral values documented above.
     *
     * @return array{score: int, band: string, components: array<string, array{name: string, score: int, weight: int, reasons: string[]}>, applied_caps: array<int, string>}
     */
    public function computeLive(): array
    {
        $input = [];

        // ── Host subsystems ─────────────────────────────────────────────
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
            // Incidents table absent (pre-Iteration-2) — every event counts.
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

        // ── Active incidents ────────────────────────────────────────────
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

        // ── Data protection ─────────────────────────────────────────────
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

    // ── Per-application sub-score (Iteration 5, §16.2) ───────────────────

    /**
     * Pure per-application computation.
     *
     * @param  array{health?: string, untriaged_events?: array<string, int>, active_incidents?: array<string, int>}  $input
     * @return array{score: int, band: string, components: array<string, array{name: string, score: int, weight: int, reasons: string[]}>, applied_caps: array<int, string>}
     */
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

        // App-scoped verdict caps — same limits, same reasoning as the
        // platform caps: the number may never read rosier than the row's
        // own Health label beside it.
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

    /**
     * Batched live sub-scores for the Applications page: two grouped
     * queries feed every row's pure computation — no per-row database
     * round-trips, no persistence (the sub-score is as derivable as the
     * platform score). Fail-soft per query: a missing table degrades to
     * zero counts, never an exception.
     *
     * @param  iterable<int, OpsApplication>  $applications
     * @return array<int, array{score: int, band: string, components: array<string, array{name: string, score: int, weight: int, reasons: string[]}>, applied_caps: array<int, string>}>
     */
    public function computeForApplications(iterable $applications): array
    {
        $ids = [];
        foreach ($applications as $application) {
            $ids[] = $application->id;
        }

        if ($ids === []) {
            return [];
        }

        // Active incident ids — events inside them are counted as part of
        // the incident, never twice (the same double-count rule the
        // platform score applies).
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

    /**
     * The app's own health — identical point mapping to the platform
     * applications component (per app: running 100 / degraded 50 /
     * stopped 0 / unknown 50), so the row badge and the platform score
     * can never disagree about what a health state is worth.
     *
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

    // ── Components ────────────────────────────────────────────────────────

    /**
     * @param  string[]  $reasons
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

    /**
     * @param  array<string, int>  $apps  running|degraded|stopped|unknown counts
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

    /**
     * @param  array<string, int>  $counts  critical|error|warning
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

    /**
     * @param  array<string, int>  $counts  critical|error|warning
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

    /**
     * @param  array<string, int>  $disks  ok|stale|missing|unreadable counts
     * @return array{name: string, score: int, weight: int, reasons: string[]}
     */
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

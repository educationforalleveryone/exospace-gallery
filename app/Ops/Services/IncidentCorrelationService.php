<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Services\OperationalAlertService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — IncidentCorrelationService (Iteration 2).
 *
 * Turns related events into ONE incident with a root-cause candidate —
 * the brief's core requirement:
 *
 *     Deployment #184 → Migration failed → Container restarted
 *     → HTTP 500 increased → Sentry exceptions increased
 *
 *     must NOT appear as five unrelated problems.
 *
 * ── Correlation algorithm (deterministic, evidence-ranked) ──────────────
 *
 * For every OPEN error/critical event E that is not yet linked to an
 * incident, in chronological order:
 *
 *   1. ADOPT: an existing OPEN or ACKNOWLEDGED incident for the same
 *      application whose last_event_at is within WINDOW_MINUTES gets E
 *      linked to it (counters, severity, timestamps updated).
 *
 *   2. CHAIN START: otherwise, look back CHAIN_LOOKBACK_MINUTES for a
 *      causal-header event (DEPLOYMENT, BUILD, MIGRATION, CONTAINER) of
 *      the same application. If found → create a NEW incident whose
 *      root-cause candidate is that header (confidence: high — a causal
 *      event demonstrably preceded the symptoms) and link BOTH events.
 *
 *   3. CLUSTER: otherwise, if other open error/critical events exist for
 *      the same application within WINDOW_MINUTES → create a
 *      medium-confidence incident grouping them.
 *
 *   4. SOLO: otherwise create a low-confidence single-event incident.
 *
 * Severity escalation: when an adopted event raises an incident's
 * severity to critical, an escalation alert fires (once per incident).
 *
 * ── Alerting ────────────────────────────────────────────────────────────
 *
 * Incident creation and critical escalation route through the EXISTING
 * OperationalAlertService (reused, not duplicated — ADR-6): its dedup
 * TTLs and per-severity Slack routing apply automatically. The alert copy
 * points at the dashboard URL, closing the loop with Slack.
 *
 * ── Robustness ──────────────────────────────────────────────────────────
 *
 * correlateAll() is idempotent (correlation_key unique + adopt-first
 * ordering) and NEVER throws to the caller: the scheduled sweep logs and
 * continues. correlate() (single event, called post-ingest for critical
 * errors) is reentrancy-guarded.
 */
class IncidentCorrelationService
{
    /** Window in which events join the same incident. */
    private const WINDOW_MINUTES = 30;

    /** How far back to search for a causal header (deploy/migration). */
    private const CHAIN_LOOKBACK_MINUTES = 60;

    /** Categories that can START a causal chain (a change, not a symptom). */
    private const CAUSAL_HEADER_CATEGORIES = ['DEPLOYMENT', 'BUILD', 'MIGRATION'];

    /** Categories that are strong symptoms even when they open a story. */
    private const SYMPTOM_CATEGORIES = ['CONTAINER', 'DOCKER'];

    private bool $busy = false;

    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    /**
     * Sweep all unlinked error/critical events into incidents.
     * Scheduled every 5 minutes (ops:correlate-incidents); also safe to
     * run manually at any time.
     *
     * @return array{incidents_created: int, events_adopted: int}
     */
    public function correlateAll(): array
    {
        if ($this->busy) {
            return ['incidents_created' => 0, 'events_adopted' => 0];
        }

        $this->busy = true;

        $created = 0;
        $adopted = 0;

        try {
            // ── Reopen pass (first): a resolved incident whose member events
            // recurred is reopened — the story came back. Runs before the
            // main sweep so recurring events adopt into the reopened
            // incident instead of spawning a duplicate.
            $this->reopenResolvedWithRecentActivity();

            // Chronological order matters: the earliest event seeds the
            // incident; later events adopt into it.
            $candidates = OpsEvent::query()
                ->whereNull('ops_incident_id')
                ->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['error', 'critical'])
                ->orderBy('first_seen_at')
                ->orderBy('id')
                ->limit(500)
                ->get();

            foreach ($candidates as $event) {
                $result = $this->correlateEvent($event);
                $created += $result['created'] ? 1 : 0;
                $adopted += $result['adopted'] ? 1 : 0;
            }
        } catch (Throwable $e) {
            // Never break the scheduler chain — the sweep retries in 5 min.
            Log::warning('IncidentCorrelation: sweep failed', ['message' => $e->getMessage()]);
        } finally {
            $this->busy = false;
        }

        return ['incidents_created' => $created, 'events_adopted' => $adopted];
    }

    /**
     * Resolved incidents with member activity inside the adoption window
     * reopen (the recurring story's events are already linked — the main
     * candidate sweep can't see them).
     */
    private function reopenResolvedWithRecentActivity(): void
    {
        OpsIncident::query()
            ->where('status', 'resolved')
            ->whereHas('events', fn ($q) => $q
                ->where('last_seen_at', '>=', now()->subMinutes(self::WINDOW_MINUTES)))
            ->get()
            ->each(function (OpsIncident $incident): void {
                $incident->update([
                    'status' => 'open',
                    'resolved_at' => null,
                    'last_event_at' => now(),
                ]);
                $this->alertIncident($incident, reopened: true);
            });
    }

    /**
     * Correlate one freshly ingested event (near-real-time path for
     * critical errors). Safe to call from the ingestor.
     */
    public function correlate(OpsEvent $event): ?OpsIncident
    {
        if ($this->busy) {
            return null;
        }

        if (! in_array($event->severity, ['error', 'critical'], true)
            || ! in_array($event->status, ['open', 'acknowledged'], true)
            || $event->ops_incident_id !== null) {
            return $event->incident;
        }

        $this->busy = true;

        try {
            $result = $this->correlateEvent($event);

            return $result['incident'];
        } catch (Throwable $e) {
            Log::debug('IncidentCorrelation: single-event correlate failed', ['message' => $e->getMessage()]);

            return null;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @return array{incident: ?OpsIncident, created: bool, adopted: bool}
     */
    private function correlateEvent(OpsEvent $event): array
    {
        // Already linked by a previous iteration (e.g. via reopen)?
        if ($event->ops_incident_id !== null) {
            return ['incident' => $event->incident, 'created' => false, 'adopted' => false];
        }

        // ── 1. ADOPT into an existing active incident ───────────────────
        $incident = OpsIncident::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('ops_application_id', $event->ops_application_id)
            ->where('last_event_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->orderByDesc('last_event_at')
            ->first();

        if ($incident !== null) {
            $this->adopt($incident, $event);

            return ['incident' => $incident, 'created' => false, 'adopted' => true];
        }

        // ── 0. SELF-ROOTED: the event IS a change (deployment failed,
        // build broke, migration failed) or a container crash — it opens
        // its own incident as the root cause. Symptoms that follow will
        // adopt into it. (Without this, a later migration could hijack a
        // deployment story as its "root".)
        if (in_array($event->category, self::CAUSAL_HEADER_CATEGORIES, true)) {
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'high',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

        if (in_array($event->category, self::SYMPTOM_CATEGORIES, true)) {
            // A container crash may itself be the start of a story (app
            // crashing on boot) — medium confidence until symptoms follow.
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'medium',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

        // Reopen path: a resolved incident for the same application whose
        // window just re-fired. If the story recurs, reopen rather than
        // spawning a duplicate.
        $resolved = OpsIncident::query()
            ->where('status', 'resolved')
            ->where('ops_application_id', $event->ops_application_id)
            ->where('last_event_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->orderByDesc('last_event_at')
            ->first();

        if ($resolved !== null) {
            $resolved->update([
                'status' => 'open',
                'resolved_at' => null,
                'event_count' => $resolved->event_count + 1,
                'last_event_at' => $event->last_seen_at ?? now(),
            ]);
            $event->update(['ops_incident_id' => $resolved->id]);
            $this->alertIncident($resolved, reopened: true);

            return ['incident' => $resolved->fresh(), 'created' => false, 'adopted' => true];
        }

        // ── STALE SEED: a backfilled/synced event older than the window
        // must never cluster with recent siblings (it would stitch two
        // unrelated stories together). It becomes a solo incident.
        if ($event->first_seen_at !== null
            && $event->first_seen_at->lt(now()->subMinutes(self::WINDOW_MINUTES))) {
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'low',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

        // ── 2. CHAIN START: a causal header precedes this symptom ──────
        // Prefer a CHANGE header (deployment/build/migration — something
        // the operator did) over a container symptom; both within the
        // lookback window and not later than the seed event.
        $windowEnd = $event->first_seen_at ?? now();

        $header = OpsEvent::query()
            ->where('ops_application_id', $event->ops_application_id)
            ->whereNull('ops_incident_id')
            ->whereIn('category', self::CAUSAL_HEADER_CATEGORIES)
            ->where('first_seen_at', '>=', now()->subMinutes(self::CHAIN_LOOKBACK_MINUTES))
            ->where('first_seen_at', '<=', $windowEnd)
            ->where('id', '!=', $event->id)
            ->orderBy('first_seen_at')
            ->first();

        if ($header === null) {
            $header = OpsEvent::query()
                ->where('ops_application_id', $event->ops_application_id)
                ->whereNull('ops_incident_id')
                ->whereIn('category', self::SYMPTOM_CATEGORIES)
                ->where('first_seen_at', '>=', now()->subMinutes(self::CHAIN_LOOKBACK_MINUTES))
                ->where('first_seen_at', '<=', $windowEnd)
                ->where('id', '!=', $event->id)
                ->orderBy('first_seen_at')
                ->first();
        }

        if ($header !== null) {
            $incident = $this->createIncident(
                rootCause: $header,
                seedEvent: $event,
                confidence: in_array($header->category, self::CAUSAL_HEADER_CATEGORIES, true) ? 'high' : 'medium',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

        // ── 3. CLUSTER: other open symptoms in the window ───────────────
        $siblings = OpsEvent::query()
            ->where('ops_application_id', $event->ops_application_id)
            ->whereNull('ops_incident_id')
            ->whereIn('severity', ['error', 'critical'])
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('first_seen_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->where('id', '!=', $event->id)
            ->get();

        $clusterRoot = $siblings->first(fn ($e) => $e->category !== 'UNKNOWN');

        $incident = $this->createIncident(
            // Solo incidents are self-rooted — the UI phrases it honestly
            // as "Unclear cause" through the confidence level.
            rootCause: $clusterRoot ?? $event,
            seedEvent: $event,
            confidence: $siblings->isEmpty() ? 'low' : 'medium',
        );

        foreach ($siblings as $sibling) {
            $this->adopt($incident, $sibling);
        }

        return ['incident' => $incident, 'created' => true, 'adopted' => false];
    }

    private function adopt(OpsIncident $incident, OpsEvent $event): void
    {
        // Refresh from DB: callers may hold stale in-memory models (e.g.
        // a cluster root that createIncident just linked). Never
        // double-count and never steal a member from another incident.
        $event->refresh();
        if ($event->ops_incident_id !== null) {
            return;
        }

        $event->update(['ops_incident_id' => $incident->id]);

        $escalated = OpsEvent::severityRank($event->severity) > OpsEvent::severityRank($incident->severity);

        // Event-time semantics (NOT now()): backfilled/synced events must
        // not reset the incident's clock, and last_event_at never moves
        // backwards.
        $eventTime = $event->last_seen_at ?? now();
        $lastEventAt = $incident->last_event_at !== null && $incident->last_event_at->gt($eventTime)
            ? $incident->last_event_at
            : $eventTime;

        $incident->update([
            'event_count' => $incident->event_count + 1,
            'last_event_at' => $lastEventAt,
            // Severity escalates, never de-escalates, while open.
            'severity' => $escalated ? $event->severity : $incident->severity,
        ]);

        if ($escalated && $incident->severity === 'critical') {
            $this->alertIncident($incident, escalated: true);
        }
    }

    private function createIncident(?OpsEvent $rootCause, OpsEvent $seedEvent, string $confidence): OpsIncident
    {
        $application = $seedEvent->application;

        $correlationKey = hash('sha256', implode('|', [
            $seedEvent->ops_application_id ?? 0,
            $rootCause?->id ?? ('solo-'.$seedEvent->fingerprint),
        ]));

        // Idempotency: if this exact root already produced an incident
        // (e.g. sweep + realtime race), reuse it.
        $existing = OpsIncident::where('correlation_key', $correlationKey)->first();
        if ($existing !== null) {
            if ($existing->status === 'resolved') {
                $existing->update(['status' => 'open', 'resolved_at' => null]);
            }
            $this->adopt($existing, $seedEvent);

            return $existing;
        }

        $title = $this->deriveTitle($rootCause, $seedEvent, $application);

        // Event-time semantics: the incident spans from its earliest
        // member to its latest — not wall-clock now().
        $firstEventAt = $rootCause?->first_seen_at ?? $seedEvent->first_seen_at;
        $times = array_filter([
            $firstEventAt,
            $seedEvent->last_seen_at,
            $rootCause?->last_seen_at,
        ]);
        $lastEventAt = $times === [] ? now() : max($times);

        $incident = OpsIncident::create([
            'ops_application_id' => $seedEvent->ops_application_id,
            'title' => mb_substr($title, 0, 250),
            'severity' => $seedEvent->severity,
            'status' => 'open',
            'root_cause_event_id' => $rootCause?->id,
            'root_cause_category' => $rootCause?->category,
            'confidence' => $confidence,
            'correlation_key' => $correlationKey,
            'event_count' => 1,
            'first_event_at' => $firstEventAt,
            'last_event_at' => $lastEventAt,
            'context' => $this->buildContext($rootCause, $seedEvent, $application),
        ]);

        // Link both the root header and the seed event.
        if ($rootCause !== null && $rootCause->id !== $seedEvent->id) {
            $rootCause->update(['ops_incident_id' => $incident->id]);
            $incident->update(['event_count' => $incident->event_count + 1]);
        }
        $seedEvent->update(['ops_incident_id' => $incident->id]);

        $this->alertIncident($incident);

        return $incident;
    }

    private function deriveTitle(?OpsEvent $rootCause, OpsEvent $seedEvent, ?OpsApplication $app): string
    {
        $appName = $app?->name ?? 'Unknown application';

        if ($rootCause !== null && in_array($rootCause->category, self::CAUSAL_HEADER_CATEGORIES, true)) {
            return match ($rootCause->category) {
                'DEPLOYMENT', 'BUILD' => 'Deployment failure cascade — '.$appName,
                'MIGRATION' => 'Migration failure cascade — '.$appName,
                default => $rootCause->title,
            };
        }

        if ($rootCause !== null && in_array($rootCause->category, self::SYMPTOM_CATEGORIES, true)) {
            return 'Container failure cascade — '.$appName;
        }

        return $seedEvent->title.' — '.$appName;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(?OpsEvent $rootCause, OpsEvent $seedEvent, ?OpsApplication $app): array
    {
        $context = [
            'chain' => [
                [
                    'category' => $rootCause?->category ?? $seedEvent->category,
                    'title' => $rootCause?->title ?? $seedEvent->title,
                    'at' => ($rootCause?->first_seen_at ?? $seedEvent->first_seen_at)?->toIso8601String(),
                ],
            ],
        ];

        // Carry deployment correlation fields up to the incident so the
        // timeline can show "Deployment #184 · commit abc1234".
        foreach ([$rootCause, $seedEvent] as $event) {
            if ($event === null) {
                continue;
            }
            foreach (['deployment_uuid', 'commit', 'duration', 'server'] as $key) {
                $value = data_get($event->context, $key);
                if ($value !== null && ! isset($context[$key])) {
                    $context[$key] = $value;
                }
            }
        }

        return $context;
    }

    /**
     * Route incident notifications through the EXISTING alerting pipeline
     * (OperationalAlertService — dedup TTLs + per-severity Slack routing
     * are inherited, not rebuilt). Dedup key is stable per incident, so a
     * reopened incident re-alerts only after the TTL window.
     */
    private function alertIncident(OpsIncident $incident, bool $reopened = false, bool $escalated = false): void
    {
        $url = rtrim((string) config('app.url'), '/').'/ops/incidents/'.$incident->id;

        $headline = match (true) {
            $reopened => 'Incident reopened',
            $escalated => 'Incident escalated to CRITICAL',
            default => 'New incident opened',
        };

        $this->alerts->alert(
            $headline.': '.$incident->title,
            sprintf(
                "Application: %s\nSeverity: %s\nRoot cause candidate: %s\nEvents correlated: %d\n\nInvestigate: %s",
                $incident->application?->name ?? 'unknown',
                strtoupper($incident->severity),
                $incident->rootCauseStatement(),
                $incident->event_count,
                $url,
            ),
            $incident->severity === 'critical' ? 'critical' : 'error',
            'ops_incident:'.$incident->correlation_key.($reopened ? ':reopened' : ($escalated ? ':escalated' : '')),
        );

        // System-actor audit trail for incident creation (operator actions
        // acknowledge/resolve are audited separately in the controller).
        try {
            AdminAuditLog::record('ops.incident.created', $incident, [
                'title' => $incident->title,
                'severity' => $incident->severity,
                'confidence' => $incident->confidence,
                'event_count' => $incident->event_count,
                'reopened' => $reopened,
                'escalated' => $escalated,
            ]);
        } catch (Throwable) {
            // Audit failure must never break correlation.
        }
    }
}

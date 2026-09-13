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

class IncidentCorrelationService
{
    private const WINDOW_MINUTES = 30;

    private const CHAIN_LOOKBACK_MINUTES = 60;

    private const CAUSAL_HEADER_CATEGORIES = ['DEPLOYMENT', 'BUILD', 'MIGRATION'];

    private const SYMPTOM_CATEGORIES = ['CONTAINER', 'DOCKER'];

    private bool $busy = false;

    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    public function correlateAll(): array
    {
        if ($this->busy) {
            return ['incidents_created' => 0, 'events_adopted' => 0];
        }

        $this->busy = true;

        $created = 0;
        $adopted = 0;

        try {
            $this->reopenResolvedWithRecentActivity();

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

    private function correlateEvent(OpsEvent $event): array
    {
        // Already linked by a previous pass (e.g. via reopen)?
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

        if (in_array($event->category, self::CAUSAL_HEADER_CATEGORIES, true)) {
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'high',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

        if (in_array($event->category, self::SYMPTOM_CATEGORIES, true)) {
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'medium',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

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

        if ($event->first_seen_at !== null
            && $event->first_seen_at->lt(now()->subMinutes(self::WINDOW_MINUTES))) {
            $incident = $this->createIncident(
                rootCause: $event,
                seedEvent: $event,
                confidence: 'low',
            );

            return ['incident' => $incident, 'created' => true, 'adopted' => false];
        }

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
        $event->refresh();
        if ($event->ops_incident_id !== null) {
            return;
        }

        $event->update(['ops_incident_id' => $incident->id]);

        $escalated = OpsEvent::severityRank($event->severity) > OpsEvent::severityRank($incident->severity);

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

        $existing = OpsIncident::where('correlation_key', $correlationKey)->first();
        if ($existing !== null) {
            if ($existing->status === 'resolved') {
                $existing->update(['status' => 'open', 'resolved_at' => null]);
            }
            $this->adopt($existing, $seedEvent);

            return $existing;
        }

        $title = $this->deriveTitle($rootCause, $seedEvent, $application);

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

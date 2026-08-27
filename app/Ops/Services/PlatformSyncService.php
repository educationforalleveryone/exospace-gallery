<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — PlatformSyncService.
 *
 * Pulls the WHOLE Coolify platform into ops_applications and turns notable
 * changes into ops_events. This is what makes the control plane
 * PLATFORM-WIDE: every application/database/service/server on the box
 * becomes visible — not just Exospace — WITHOUT agents, SSH, or Docker
 * socket access (ADR-2 in docs/OPS_DISCOVERY_AUDIT.md).
 *
 * Idempotent: run it every 5 minutes (scheduled) or manually. Each sync:
 *
 *   1. Upserts servers / applications / databases / services into
 *      ops_applications (matching by Coolify resource UUID).
 *   2. Creates an event when a resource's status DEGRADES (running →
 *      exited/unhealthy/restarting) — recovery is visible on the
 *      application row itself.
 *   3. Inspects recent deployments per application; failed deployments
 *      become BUILD/DEPLOYMENT events with commit + duration context
 *      (deduped by fingerprint — an old failure never re-pages).
 *   4. Records a single INFRASTRUCTURE event (rate-limited via cache, like
 *      OperationalAlertService's dedup) when the Coolify API itself is
 *      unreachable, so a dead control-plane feed is itself observable.
 *
 * Every field is optional-tolerant: Coolify's API surface differs between
 * versions; missing fields degrade to 'unknown', never to an exception.
 */
class PlatformSyncService
{
    private const COOLIFY_UNREACHABLE_CACHE_KEY = 'ops:sync:coolify-unreachable-alerted';

    public function __construct(
        private readonly CoolifyApiClient $coolify,
        private readonly OpsEventIngestor $ingestor,
    ) {}

    /**
     * @return array{applications: int, events_created: int, api_ok: bool}
     */
    public function sync(): array
    {
        if (! $this->coolify->isConfigured()) {
            return ['applications' => 0, 'events_created' => 0, 'api_ok' => false];
        }

        $events = 0;
        $appsSeen = 0;

        // ── Servers ────────────────────────────────────────────────────
        // Servers are resources too (kind=server) so the dashboard can show
        // the DigitalOcean box itself, its health and (where the Coolify
        // version exposes it) utilization in meta.
        foreach ($this->coolify->servers() as $server) {
            $this->upsertResource($server, 'server', 'coolify');
            $appsSeen++;
        }

        // ── Applications (the core of platform-wide visibility) ────────
        $selfUuid = config('ops.self.coolify_uuid');

        foreach ($this->coolify->applications() as $application) {
            $uuid = (string) ($application['uuid'] ?? '');

            $opsApp = $this->upsertResource($application, 'application', 'coolify');

            if ($opsApp !== null) {
                $appsSeen++;

                // Tie the self application (Exospace) to its Coolify row so
                // local errors and Coolify deployments correlate on one row.
                if ($selfUuid && $uuid === $selfUuid) {
                    $this->markSelf($opsApp);
                }

                // Deployments (best-effort per application; endpoint varies
                // by Coolify version).
                $events += $this->syncDeployments($opsApp, $uuid);
            }
        }

        // ── Databases & services (MySQL, Redis, ... — shared infra) ────
        foreach ($this->coolify->databases() as $database) {
            $this->upsertResource($database, 'database', 'coolify');
            $appsSeen++;
        }

        foreach ($this->coolify->services() as $service) {
            $this->upsertResource($service, 'service', 'coolify');
            $appsSeen++;
        }

        $this->clearUnreachableAlert();

        return ['applications' => $appsSeen, 'events_created' => $events, 'api_ok' => true];
    }

    /**
     * Upsert one Coolify resource row; emit a degrade event on status
     * transitions into an unhealthy state.
     */
    private function upsertResource(array $data, string $kind, string $provider): ?OpsApplication
    {
        try {
            $uuid = (string) ($data['uuid'] ?? '');
            $name = (string) ($data['name'] ?? $uuid);
            $status = strtolower((string) ($data['status'] ?? 'unknown'));

            if ($uuid === '' || $name === '') {
                return null;
            }

            $app = OpsApplication::where('provider_uuid', $uuid)->first();

            if ($app === null) {
                $app = OpsApplication::create([
                    'slug' => mb_substr($kind.':'.$uuid, 0, 100),
                    'name' => $name,
                    'provider' => $provider,
                    'provider_uuid' => $uuid,
                    'kind' => $kind,
                    'environment' => 'production',
                    'url' => $this->extractUrl($data),
                    'status' => $status,
                    'health' => OpsApplication::deriveHealth($status),
                    'status_checked_at' => now(),
                    'last_seen_at' => now(),
                    'meta' => $this->extractMeta($data),
                ]);

                // First observation of an UNHEALTHY resource is immediately
                // notable (a resource added in a bad state).
                if (in_array($app->health, ['degraded', 'stopped'], true)) {
                    $this->ingestStatusEvent($app, 'unknown', $status);
                }

                return $app;
            }

            $previousHealth = $app->health;
            $app->fill([
                'name' => $name,
                'status' => $status,
                'health' => OpsApplication::deriveHealth($status),
                'status_checked_at' => now(),
                'last_seen_at' => now(),
                'meta' => array_merge($app->meta ?? [], $this->extractMeta($data)),
            ]);

            if ($app->isDirty('health')) {
                // Transition into a bad state = event. Recovery (bad →
                // running) updates the row; no event needed — the dashboard
                // shows current status, and recovery pages would be noise.
                if (in_array($app->health, ['degraded', 'stopped'], true)) {
                    $this->ingestStatusEvent($app, $previousHealth, $status);
                }
            }

            if ($app->isDirty()) {
                $app->save();
            }

            return $app;
        } catch (Throwable $e) {
            Log::debug('PlatformSync: failed to upsert resource', [
                'kind' => $kind,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Inspect recent deployments of one application; emit events for failed
     * ones (deduped by fingerprint — a deployment failure pages once).
     */
    private function syncDeployments(OpsApplication $app, string $uuid): int
    {
        $limit = max(1, (int) config('ops.platform_sync.deployments_limit', 5));
        $deployments = array_slice($this->coolify->applicationDeployments($uuid), 0, $limit);

        $created = 0;

        foreach ($deployments as $deployment) {
            $status = strtolower((string) ($deployment['status'] ?? ''));

            if (! in_array($status, ['failed', 'cancelled'], true)) {
                continue;
            }

            $deploymentUuid = (string) ($deployment['deployment_uuid']
                ?? $deployment['uuid']
                ?? ('recent-'.($deployment['created_at'] ?? '')));

            $commit = (string) ($deployment['commit'] ?? '');

            $duration = null;
            if (isset($deployment['duration']) && is_numeric($deployment['duration'])) {
                $duration = round((float) $deployment['duration']).'s';
            }

            $event = $this->ingestor->record([
                'source' => 'coolify',
                'category' => 'DEPLOYMENT',
                'severity' => 'critical',
                'title' => 'Deployment failed — '.$app->name,
                'message' => 'Coolify deployment of "'.$app->name.'" finished with status "'.$status.'".',
                'application_id' => $app->id,
                'environment' => $app->environment,
                'context' => [
                    'deployment_uuid' => $deploymentUuid,
                    'deployment_status' => $status,
                    'commit' => $commit !== '' ? $commit : null,
                    'duration' => $duration,
                    'pull_request_id' => $deployment['pull_request_id'] ?? null,
                    'server' => $deployment['server_name'] ?? null,
                ],
            ]);

            if ($event !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Alert (once per cooldown, cache-guarded exactly like
     * OperationalAlertService dedup) that the platform feed itself is down.
     */
    public function recordApiUnreachable(): void
    {
        // 2-hour cooldown — a persistent outage re-pages; a blip doesn't.
        if (Cache::has(self::COOLIFY_UNREACHABLE_CACHE_KEY)) {
            return;
        }

        Cache::put(self::COOLIFY_UNREACHABLE_CACHE_KEY, now()->toIso8601String(), now()->addHours(2));

        $this->ingestor->record([
            'source' => 'system',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'warning',
            'title' => 'Coolify API unreachable — platform sync paused',
            'message' => 'The Coolify API did not respond. Platform-wide status (deployments, containers) cannot be refreshed until it is reachable again. Deployments and container status may be stale.',
            'context' => ['base_url' => config('services.coolify.api_base_url')],
        ]);
    }

    private function clearUnreachableAlert(): void
    {
        Cache::forget(self::COOLIFY_UNREACHABLE_CACHE_KEY);
    }

    private function ingestStatusEvent(OpsApplication $app, string $from, string $to): void
    {
        $this->ingestor->record([
            'source' => 'coolify',
            'category' => $app->kind === 'server' ? 'INFRASTRUCTURE' : 'CONTAINER',
            'severity' => $to === 'exited' || str_contains($to, 'exited') ? 'error' : 'warning',
            'title' => ($app->kind === 'server' ? 'Server status degraded — ' : 'Container status degraded — ').$app->name,
            'message' => sprintf(
                'Resource "%s" (kind: %s) transitioned from "%s" to "%s" according to the Coolify API.',
                $app->name, $app->kind, $from !== '' ? $from : 'unknown', $to,
            ),
            'application_id' => $app->id,
            'environment' => $app->environment,
            'context' => [
                'provider' => 'coolify',
                'previous_health' => $from,
                'current_status' => $to,
            ],
        ]);
    }

    private function markSelf(OpsApplication $app): void
    {
        if ($app->is_self) {
            return;
        }

        // The 'self' row (created by OpsEventIngestor::selfApplication) and
        // this Coolify row are the same application. Prefer the self row's
        // stable slug; merge identity onto the Coolify row so ALL events
        // (local errors + Coolify deployments) correlate on ONE row.
        $self = OpsApplication::where('is_self', true)->first();

        if ($self !== null && $self->id !== $app->id) {
            // Re-point any events already attributed to the Coolify row.
            OpsEvent::where('ops_application_id', $app->id)
                ->update(['ops_application_id' => $self->id]);

            $self->fill([
                'provider' => 'coolify',
                'provider_uuid' => $app->provider_uuid,
                'status' => $app->status,
                'health' => $app->health,
                'status_checked_at' => $app->status_checked_at,
                'meta' => array_merge($app->meta ?? [], $self->meta ?? []),
            ]);

            // Keep the richer URL: Coolify's fqdn beats a missing self URL;
            // an explicitly configured APP_URL wins over both.
            if ($app->url && ! $self->url) {
                $self->url = $app->url;
            }

            $self->save();
            $app->delete();

            return;
        }

        $app->is_self = true;
        $app->slug = 'self';
        $app->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeta(array $data): array
    {
        $meta = [];

        foreach (['image' => 'image', 'commit' => 'commit', 'fqdn' => 'domains',
            'server_name' => 'server', 'source' => 'source', 'environment_name' => 'environment_name'] as $key => $metaKey) {
            if (! empty($data[$key])) {
                $meta[$metaKey] = is_scalar($data[$key]) ? (string) $data[$key] : null;
            }
        }

        return $meta;
    }

    private function extractUrl(array $data): ?string
    {
        $fqdn = (string) ($data['fqdn'] ?? '');

        if ($fqdn === '') {
            return null;
        }

        // Coolify stores comma-separated domains; first one is primary.
        $first = trim(explode(',', $fqdn)[0]);

        return $first !== '' ? $first : null;
    }
}

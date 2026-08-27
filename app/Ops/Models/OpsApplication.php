<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Ops\Models\OpsApplication
 *
 * A monitored THING on the platform: a Coolify application, a Coolify
 * database/service, a Coolify server, or any external application that
 * reports through the ingestion API.
 *
 * Providers populate this table:
 *   - coolify : PlatformSyncService (every 5 min, from the Coolify API)
 *   - ingest  : OpsIngestController (token-authenticated HTTP POSTs)
 *   - self    : OpsServiceProvider boot (Exospace itself)
 *   - manual  : an operator (not implemented yet — future iteration)
 *
 * NOTE: this model is intentionally isolated from the App\Models namespace
 * (ADR-1 in docs/OPS_DISCOVERY_AUDIT.md): no non-Ops code references it,
 * so the whole App\Ops module stays extractable.
 */
class OpsApplication extends Model
{
    protected $table = 'ops_applications';

    protected $fillable = [
        'slug', 'name', 'provider', 'provider_uuid', 'kind', 'environment',
        'url', 'status', 'health', 'status_checked_at', 'last_seen_at',
        'meta', 'is_self',
    ];

    protected $casts = [
        'meta' => 'array',
        'status_checked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_self' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(OpsEvent::class);
    }

    /**
     * Derive the display rollup from a raw Coolify status string.
     *
     * Coolify statuses look like: running:healthy, running:unhealthy,
     * exited:1, restarting, degrading, starting, queued...
     */
    public static function deriveHealth(?string $status): string
    {
        $status = strtolower((string) $status);

        if ($status === '' || $status === 'unknown') {
            return 'unknown';
        }

        if (str_starts_with($status, 'running')) {
            return str_contains($status, 'unhealthy') ? 'degraded' : 'running';
        }

        if (in_array($status, ['restarting', 'starting', 'degrading'], true)) {
            return 'degraded';
        }

        if (str_starts_with($status, 'exited') || $status === 'stopped') {
            return 'stopped';
        }

        return 'unknown';
    }

    /**
     * Human label for the UI.
     */
    public function healthLabel(): string
    {
        return match ($this->health) {
            'running' => 'Running',
            'degraded' => 'Degraded',
            'stopped' => 'Stopped',
            default => 'Unknown',
        };
    }
}

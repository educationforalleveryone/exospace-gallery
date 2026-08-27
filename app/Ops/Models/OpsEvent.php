<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Ops\Models\OpsEvent
 *
 * The normalized, DEDUPLICATED event/error record. One row per distinct
 * fingerprint (application + category + normalized message) — a recurring
 * error increments occurrence_count instead of inserting new rows.
 *
 * Lifecycle:
 *   open         — being seen / recurring
 *   acknowledged — an operator has seen it (Iteration 2 UI)
 *   resolved     — auto-resolved after auto_resolve_days without
 *                  recurrence, or (later iteration) resolved by an
 *                  operator. A resolved event that recurs REOPENS with a
 *                  fresh episode (occurrence_count reset, total_count kept).
 */
class OpsEvent extends Model
{
    protected $table = 'ops_events';

    public const SEVERITIES = ['critical', 'error', 'warning', 'info'];

    public const CATEGORIES = [
        'DATABASE', 'MIGRATION', 'APPLICATION', 'PHP', 'LARAVEL', 'REDIS',
        'QUEUE', 'WEBHOOK', 'BUILD', 'DEPLOYMENT', 'DOCKER', 'CONTAINER',
        'STORAGE', 'NETWORK', 'AUTHENTICATION', 'AUTHORIZATION',
        'EXTERNAL_SERVICE', 'BACKUP', 'INFRASTRUCTURE', 'UNKNOWN',
    ];

    protected $fillable = [
        'fingerprint', 'ops_application_id', 'source', 'category', 'severity',
        'title', 'message', 'occurrence_count', 'total_count',
        'first_seen_at', 'last_seen_at', 'status', 'resolved_at',
        'environment', 'context', 'classification',
    ];

    protected $casts = [
        'context' => 'array',
        'classification' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpsApplication::class, 'ops_application_id');
    }

    /**
     * Severity rank for ordering/comparison (higher = more serious).
     */
    public static function severityRank(string $severity): int
    {
        return match (strtolower($severity)) {
            'critical' => 4,
            'error' => 3,
            'warning' => 2,
            'info' => 1,
            default => 1,
        };
    }

    /**
     * The "why it matters" line rendered under the error title in the UI.
     */
    public function impactStatement(): string
    {
        return match ($this->category) {
            'DATABASE' => 'Database problems affect every request that reads or writes data.',
            'MIGRATION' => 'The application may be running against an incompatible database schema.',
            'REDIS' => 'Cache and queue subsystems may be failing; sessions and jobs can be affected.',
            'QUEUE' => 'Background work (emails, exports, image jobs) may be delayed or lost.',
            'WEBHOOK' => 'Money/billing or integration events may not be applied.',
            'BUILD', 'DEPLOYMENT' => 'The latest code may not be live; the running version may be stale.',
            'DOCKER', 'CONTAINER' => 'A container is unhealthy — the service may be partly or fully down.',
            'STORAGE' => 'Uploads, backups, or generated files may be failing.',
            'NETWORK' => 'Connectivity to internal or external services is impaired.',
            'AUTHENTICATION' => 'Sign-ins or authenticated API calls may be failing.',
            'AUTHORIZATION' => 'Access control checks are failing — inspect before assuming misuse.',
            'EXTERNAL_SERVICE' => 'A third-party dependency (payments, mail, storage) is failing.',
            'BACKUP' => 'Data-loss protection is compromised — check backup freshness.',
            'INFRASTRUCTURE' => 'The server or platform layer is impaired; everything on it is at risk.',
            default => 'Application errors may be affecting users right now.',
        };
    }

    /**
     * Likely causes from the classifier (never certain — phrased as
     * "likely" by design; see docs/OPS_DISCOVERY_AUDIT.md ADR-4).
     *
     * @return string[]
     */
    public function likelyCauses(): array
    {
        $causes = $this->classification['likely_causes'] ?? [];

        return is_array($causes) ? array_values(array_filter($causes, 'is_string')) : [];
    }

    /**
     * @return string[]
     */
    public function recommendedDiagnostics(): array
    {
        $recs = $this->classification['recommended_diagnostics'] ?? [];

        return is_array($recs) ? array_values(array_filter($recs, 'is_string')) : [];
    }
}

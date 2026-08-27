<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Ops\Models\OpsDiagnosticRun
 *
 * One execution of an allow-listed diagnostic (Iteration 3). Immutable after
 * creation: a run is a point-in-time snapshot — re-running creates a NEW row.
 * Retention: pruned after ops.diagnostics.retention_days (default 30) by the
 * existing ops:prune-events command; a run is always reproducible on demand,
 * so short retention loses nothing.
 */
class OpsDiagnosticRun extends Model
{
    public const STATUSES = ['healthy', 'degraded', 'failed', 'inconclusive'];

    protected $table = 'ops_diagnostic_runs';

    // created_at only — runs are immutable snapshots, no updated_at column.
    public $timestamps = false;

    protected $fillable = [
        'diagnostic_id', 'ops_application_id', 'actor_id',
        'source', 'source_id',
        'status', 'summary', 'findings', 'interpretation', 'next_steps',
        'duration_ms', 'created_at',
    ];

    protected $casts = [
        'findings' => 'array',
        'next_steps' => 'array',
        'created_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpsApplication::class, 'ops_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }

    /**
     * UI label for the run's status.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'healthy' => 'PASSED',
            'degraded' => 'ATTENTION',
            'failed' => 'FAILED',
            'inconclusive' => 'INCONCLUSIVE',
            default => strtoupper((string) $this->status),
        };
    }

    /**
     * Where this run was triggered from (UI rendering):
     *  manual  — the diagnostics catalog page
     *  event   — a one-click button on an error detail page (source_id = event)
     *  incident — a one-click button on an incident timeline page (source_id = incident)
     */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'event' => 'from event #'.($this->source_id ?? '?'),
            'incident' => 'from incident #'.($this->source_id ?? '?'),
            default => 'manual',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'event' => 'from event #'.($this->source_id ?? '?'),
            'incident' => 'from incident #'.($this->source_id ?? '?'),
            default => 'manual',
        };
    }
}

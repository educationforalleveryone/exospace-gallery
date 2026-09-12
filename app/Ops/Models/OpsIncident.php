<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpsIncident extends Model
{
    protected $table = 'ops_incidents';

    protected $fillable = [
        'ops_application_id', 'title', 'severity', 'status',
        'root_cause_event_id', 'root_cause_category', 'confidence',
        'correlation_key', 'event_count', 'first_event_at', 'last_event_at',
        'acknowledged_at', 'resolved_at', 'context',
    ];

    protected $casts = [
        'context' => 'array',
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpsApplication::class, 'ops_application_id');
    }

    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(OpsEvent::class, 'root_cause_event_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OpsEvent::class, 'ops_incident_id');
    }

    public function timeline(): HasMany
    {
        return $this->events()->orderBy('first_seen_at')->orderBy('id');
    }

    public function rootCauseStatement(): string
    {
        $category = $this->root_cause_category ?? 'UNKNOWN';

        $phrase = match ($this->confidence) {
            'high' => 'Likely cause',
            'medium' => 'Possible cause',
            default => 'Unclear cause',
        };

        if ($this->rootCause !== null) {
            return $phrase.': '.$this->rootCause->title.' ('.$category.')';
        }

        return $phrase.': '.strtolower($category).' problem (no single root event identified)';
    }

    public function impactStatement(): string
    {
        return $this->rootCause?->impactStatement()
            ?? 'Multiple correlated problems are affecting this application.';
    }

    public function relatedContext(): array
    {
        return is_array($this->context) ? $this->context : [];
    }
}

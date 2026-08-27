<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Ops\Models\OpsIncident
 *
 * A correlated group of events forming one operational story. Created by
 * IncidentCorrelationService; never hand-built.
 *
 * Lifecycle: open → acknowledged → resolved → (reopen on new recurrence).
 * Acknowledge/resolve are operator actions (audited via AdminAuditLog);
 * a resolved incident whose story recurs (a new event correlates to it
 * within its window) reopens.
 *
 * Root cause is always a CANDIDATE with an explicit confidence level —
 * the product must never claim certainty it doesn't have.
 */
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

    /**
     * Member events ordered chronologically — the timeline.
     */
    public function timeline(): HasMany
    {
        return $this->events()->orderBy('first_seen_at')->orderBy('id');
    }

    /**
     * Confidence-phrased root-cause statement for the UI. The language is
     * deliberate (brief requirement): "Likely cause" / "Possible cause" /
     * "Unclear" — never "The cause".
     */
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

    /**
     * The "why it matters" line (borrows the root event's statement).
     */
    public function impactStatement(): string
    {
        return $this->rootCause?->impactStatement()
            ?? 'Multiple correlated problems are affecting this application.';
    }

    /**
     * Notable context entries (deployment uuid, commit, ...) for the
     * "Related" section of the incident page.
     *
     * @return array<string, mixed>
     */
    public function relatedContext(): array
    {
        return is_array($this->context) ? $this->context : [];
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\VenueTemplate;
use App\Models\VenueTemplateSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 5 "Authoring" (roadmap P2.1, §9.2 #3): bounded snapshot history
 * with one-click restore.
 *
 * THE CONTRACT
 * ------------
 *   - capture()  — called BEFORE an admin save overwrites a venue. The
 *                  snapshot IS the state about to be overwritten, so the
 *                  list on the edit page reads as "what you would go back
 *                  to". Restore captures the current state first, so a
 *                  restore is itself reversible — no dead ends.
 *   - restore()  — fills the venue from the snapshot payload and saves.
 *                  Never touches slug / is_draft / published_at (identity
 *                  and publication are not content).
 *   - Retention  — newest 5 per venue (§9.2: "not git"). Pruned inside the
 *                  same transaction as the insert.
 *
 * CACHE COOPERATION (§10.7)
 * -------------------------
 * VenueConfigExporter keys its cache on the venue's updated_at, so a
 * restore (a normal Eloquent save) busts every gallery's cached config on
 * the very next render — "rollback visible immediately" holds without any
 * cache-specific code here.
 */
class VenueSnapshotManager
{
    /** §9.2 #3: "the last 5 saves" — hard cap, prune after insert. */
    public const MAX_PER_VENUE = 5;

    /**
     * The restorable content keys. Everything an admin edits through the
     * venue form, EXCEPT identity/publication/bookkeeping (slug, is_draft,
     * published_at, view_count, thumbnail/asset paths — files are managed
     * by uploads, snapshots only track configuration).
     */
    public const CONTENT_KEYS = [
        'name',
        'description',
        'category',
        'plan_required',
        'capacity_min',
        'capacity_max',
        'tags',
        'visual_config',
        'material_config',
        'decorations',
        'lighting_fixtures',
        'supported_layouts',
        'default_settings',
        'version',
        'sort_order',
        'is_featured',
        'is_active',
    ];

    /**
     * Snapshot the venue's current content state (call BEFORE overwriting it).
     */
    public function capture(VenueTemplate $venue, ?string $label = null, ?User $actor = null): VenueTemplateSnapshot
    {
        return DB::transaction(function () use ($venue, $label, $actor) {
            $snapshot = VenueTemplateSnapshot::create([
                'venue_template_id' => $venue->id,
                'label'             => $label,
                'config'            => $this->payloadFor($venue),
                'created_by'        => $actor?->id,
            ]);

            $this->prune($venue->id);

            return $snapshot;
        });
    }

    /**
     * Restore a snapshot: captures the CURRENT state first (reversibility),
     * applies the snapshot content, saves. Returns before/after for audit.
     *
     * @return array{before: array, after: array, safety: VenueTemplateSnapshot}
     */
    public function restore(VenueTemplateSnapshot $snapshot, ?User $actor = null): array
    {
        /** @var VenueTemplate $venue */
        $venue = VenueTemplate::query()->lockForUpdate()->findOrFail($snapshot->venue_template_id);

        $before = $this->payloadFor($venue);

        // Safety net: the state we are about to roll back becomes a snapshot
        // itself, so even the restore can be undone.
        $safety = $this->capture($venue, 'before restore', $actor);

        $venue->fill(array_intersect_key($snapshot->config ?? [], array_flip(self::CONTENT_KEYS)));
        $venue->save();

        return [
            'before' => $before,
            'after'  => $this->payloadFor($venue->fresh()),
            'safety' => $safety,
        ];
    }

    /**
     * The snapshot payload for a venue (only CONTENT_KEYS, only scalar/array
     * JSON-safe values — model state minus identity/publication).
     */
    public function payloadFor(VenueTemplate $venue): array
    {
        $payload = [];
        foreach (self::CONTENT_KEYS as $key) {
            $payload[$key] = $venue->getAttribute($key);
        }

        return $payload;
    }

    /**
     * Keep only the newest MAX_PER_VENUE snapshots for a venue.
     * Rows beyond the cap are deleted oldest-first (id as tiebreaker for
     * identical timestamps inside one transaction).
     */
    private function prune(int $venueId): void
    {
        $keepIds = VenueTemplateSnapshot::query()
            ->where('venue_template_id', $venueId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PER_VENUE)
            ->pluck('id');

        VenueTemplateSnapshot::query()
            ->where('venue_template_id', $venueId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\VenueTemplate;
use App\Models\VenueTemplateSnapshot;
use Illuminate\Support\Facades\DB;

class VenueSnapshotManager
{
    public const MAX_PER_VENUE = 5;

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

    public function restore(VenueTemplateSnapshot $snapshot, ?User $actor = null): array
    {
        /**
 * @var VenueTemplate $venue
 */
        $venue = VenueTemplate::query()->lockForUpdate()->findOrFail($snapshot->venue_template_id);

        $before = $this->payloadFor($venue);

        $safety = $this->capture($venue, 'before restore', $actor);

        $venue->fill(array_intersect_key($snapshot->config ?? [], array_flip(self::CONTENT_KEYS)));
        $venue->save();

        return [
            'before' => $before,
            'after'  => $this->payloadFor($venue->fresh()),
            'safety' => $safety,
        ];
    }

    public function payloadFor(VenueTemplate $venue): array
    {
        $payload = [];
        foreach (self::CONTENT_KEYS as $key) {
            $payload[$key] = $venue->getAttribute($key);
        }

        return $payload;
    }

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

<?php

namespace App\Services;

use App\Models\VenueTemplate;
use App\Models\Gallery;
use App\Support\ResilientCache;

class VenueConfigExporter
{
    public const SCHEMA = 's7';

    public const VENUE_OWNED_VISUAL_KEYS = [
        'background_color', 'fog_color', 'fog_near', 'fog_far', 'environment',
        'open_air', 'layout_shape', 'wall_height', 'wall_depth',
        'ceiling_type', 'ceiling_color', 'ceiling_height',
        'structure_pass', 'placement_mode', 'floor_reflection',
        'structure', 'bays', 'glazing_wall', 'corridor_width',
        'sun_shadows', 'floor_edge_fade', 'void_depth_gradient',
        'glass_material', 'colonnade_tint',
        'wing_heights', 'glazing_walls',
        'nebula',
        // lighting rig + legibility floor
        'ambient_color', 'ambient_intensity', 'spot_intensity',
        'fill_intensity', 'hemisphere_intensity', 'env_intensity',
        'tone_mapping_exposure',
        'artwork_light_base', 'artwork_light_pool_cap',
        // curation + presentation (s3 — nested objects, owned wholesale)
        'placement', 'post_fx',
        'media_wall',
        'artwork_reactive',
        'garden',
        'ceiling_fill_light',
        'field_radius_bonus',
        'field_radius_min',
        'hemisphere_sky_color',
        'hemisphere_ground_color',
        'lake',
    ];

    public const VENUE_OWNED_MATERIAL_KEYS = [
        'texture_tint',
        'wall_color', 'wall_roughness', 'wall_metalness', 'wall_normal_strength',
        'floor_color', 'floor_roughness', 'floor_metalness',
        'floor_normal_strength', 'floor_tile_meters',
    ];

    public static function isVenueOwnedKey(string $key): bool
    {
        return in_array($key, self::VENUE_OWNED_VISUAL_KEYS, true)
            || str_starts_with($key, 'void_');
    }

    public static function ownedKeyPayload(): array
    {
        return [
            'venue_owned_visual'   => array_values(self::VENUE_OWNED_VISUAL_KEYS),
            'venue_owned_material' => array_values(self::VENUE_OWNED_MATERIAL_KEYS),
        ];
    }

    /**
     * Per-gallery override payloads (saved JSON and ?override= runtime patches)
     * are flat maps of scalar viewer settings. Anything deeper or oversized is
     * dropped so arbitrary nested request data can never reach the config that
     * ships to the viewer.
     */
    public const GALLERY_OVERRIDE_BUCKETS = ['visual_config', 'material_config', 'post_fx'];
    private const GALLERY_OVERRIDE_MAX_KEYS = 40;
    private const GALLERY_OVERRIDE_MAX_KEY_LENGTH = 64;
    private const GALLERY_OVERRIDE_MAX_VALUE_LENGTH = 255;

    public static function sanitizeGalleryOverrides(array $overrides): array
    {
        $clean = [];

        foreach (self::GALLERY_OVERRIDE_BUCKETS as $bucket) {
            $values = $overrides[$bucket] ?? null;
            if (! is_array($values)) {
                continue;
            }

            $sanitized = [];
            foreach (array_slice($values, 0, self::GALLERY_OVERRIDE_MAX_KEYS, true) as $key => $value) {
                if (is_int($key) || mb_strlen((string) $key) > self::GALLERY_OVERRIDE_MAX_KEY_LENGTH) {
                    continue;
                }
                if (is_bool($value) || is_int($value) || is_float($value)) {
                    $sanitized[$key] = $value;
                } elseif (is_string($value) && mb_strlen($value) <= self::GALLERY_OVERRIDE_MAX_VALUE_LENGTH) {
                    $sanitized[$key] = $value;
                }
            }

            if ($sanitized !== []) {
                $clean[$bucket] = $sanitized;
            }
        }

        return $clean;
    }

    public function presetForGallery(Gallery $gallery): string
    {
        $venuePreset = $gallery->venueTemplate?->default_settings['lighting_preset'] ?? null;

        return is_string($venuePreset) && $venuePreset !== ''
            ? $venuePreset
            : ($gallery->lighting_preset ?: 'bright');
    }

    public function layoutForGallery(Gallery $gallery): string
    {
        $layout  = $gallery->room_layout ?: 'square';
        $venue   = $gallery->venueTemplate;

        if (!$venue) {
            return $layout;
        }

        return $venue->supportsLayout($layout)
            ? $layout
            : ($venue->default_settings['room_layout'] ?? 'square');
    }
    public function forGallery(Gallery $gallery): ?array
    {
        // Fresh read at entry: a venue save (or owner plan change) must be
        // visible to galleries immediately, so the cache key may never be
        // built from a relation loaded earlier in this instance's lifetime.
        $venue = $gallery->venueTemplate()->first();
        $venueTs = $venue?->updated_at?->timestamp ?? '0';
        $venueSig = $this->venueSignature($venue);
        $plan = $gallery->user()->value('plan') ?? 'free';
        $cacheKey = "venue_config:{$gallery->id}:{$gallery->updated_at?->timestamp}:v{$venueTs}:{$venueSig}:p{$plan}:" . self::SCHEMA;

        return ResilientCache::flexible($cacheKey, [now()->addHour(), now()->addHours(2)], function () use ($gallery, $venue, $plan) {
            return $this->buildConfig($gallery, $venue, $plan);
        });
    }

    private function venueSignature(?VenueTemplate $venue): string
    {
        if (!$venue) {
            return 'nov';
        }

        return substr(sha1((string) json_encode([
            $venue->visual_config,
            $venue->material_config,
            $venue->decorations,
            $venue->lighting_fixtures,
            $venue->default_settings,
            $venue->supported_layouts,
            $venue->hdri_path,
            $venue->plan_required,
            $venue->is_draft,
            $venue->is_active,
            $venue->version,
            $venue->archived_at?->timestamp,
        ])), 0, 16);
    }

    private function buildConfig(Gallery $gallery, ?VenueTemplate $venue, string $plan): ?array
    {
        if (!$venue) {
            return null;
        }

        $config = $venue->toViewerConfig();

        $config += self::ownedKeyPayload();

        $config['effective_settings'] = array_merge(
            $venue->default_settings ?? [],
            array_filter([
                'wall_texture'    => $gallery->wall_texture,
                'floor_material'  => $gallery->floor_material,
                'frame_style'     => $gallery->frame_style,
                'lighting_preset' => $this->presetForGallery($gallery),
                'room_layout'     => $this->layoutForGallery($gallery),
            ], fn ($v) => !is_null($v))
        );

        $overrides = $gallery->visualOverridesArray();

        $overrideVisual = array_filter($overrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        foreach (array_keys($overrideVisual) as $key) {
            if (self::isVenueOwnedKey((string) $key)) {
                unset($overrideVisual[$key]);
            }
        }

        if (!empty($overrideVisual)) {
            $config['visual_config'] = array_merge(
                $config['visual_config'] ?? [],
                $overrideVisual
            );
        }

        $venueVisual = $venue->visual_config ?? [];
        foreach (self::VENUE_OWNED_VISUAL_KEYS as $owned) {
            if (array_key_exists($owned, $venueVisual) && $venueVisual[$owned] !== null) {
                $config['visual_config'][$owned] = $venueVisual[$owned];
            }
        }
        foreach (array_keys($config['visual_config'] ?? []) as $key) {
            if (str_starts_with((string) $key, 'void_') && !array_key_exists($key, $venueVisual)) {
                unset($config['visual_config'][$key]); // a venue that never declared a void effect can never grow one from an override
            }
        }

        if (!empty($overrides['material_config'])) {
            $overrideMaterial = array_filter($overrides['material_config'], fn ($v) => !is_null($v));
            foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
                unset($overrideMaterial[$owned]);
            }
            if (!empty($overrideMaterial)) {
                $config['material_config'] = array_merge(
                    $config['material_config'] ?? [],
                    $overrideMaterial
                );
            }
        }
        $venueMaterial = $venue->material_config ?? [];
        foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
            if (array_key_exists($owned, $venueMaterial) && $venueMaterial[$owned] !== null) {
                $config['material_config'][$owned] = $venueMaterial[$owned];
            }
        }

        $venuePlan = $venue->plan_required ?: 'free';
        $visitorPlan = $this->planRank($venuePlan) > $this->planRank($plan)
            ? $venuePlan      // grandfathered above-plan venue: render at venue tier
            : $plan;
        $config['decorations'] = array_values(array_filter(
            $config['decorations'] ?? [],
            function ($dec) use ($visitorPlan) {
                $required = $dec['plan_required'] ?? 'free';
                return $this->planSees($visitorPlan, $required);
            }
        ));

        // Resolve decoration model paths to absolute URLs.
        foreach ($config['decorations'] as &$dec) {
            if (!empty($dec['model_path'])) {
                $dec['model_url'] = asset('storage/' . ltrim($dec['model_path'], '/'));
            }
        }
        unset($dec);

        return $config;
    }

    public function forVenue(VenueTemplate $venue): array
    {
        return $venue->toViewerConfig();
    }

    public function forVenuePreview(VenueTemplate $venue): array
    {
        $config = $venue->toViewerConfig();

        // s4: same self-describing ownership contract as forGallery().
        $config += self::ownedKeyPayload();

        $config['effective_settings'] = $venue->default_settings ?? [];

        $visitorPlan = $venue->plan_required ?: 'free';

        $config['decorations'] = array_values(array_filter(
            $config['decorations'] ?? [],
            fn ($dec) => $this->planSees($visitorPlan, $dec['plan_required'] ?? 'free')
        ));

        foreach ($config['decorations'] as &$dec) {
            if (!empty($dec['model_path'])) {
                $dec['model_url'] = asset('storage/' . ltrim($dec['model_path'], '/'));
            }
        }
        unset($dec);

        return $config;
    }

    public function forGalleryPreview(Gallery $gallery, array $runtimeOverrides = []): ?array
    {
        $config = $this->forGallery($gallery);
        if (!$config) return null;

        $runtimeVisual = array_filter($runtimeOverrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        foreach (array_keys($runtimeVisual) as $key) {
            if (self::isVenueOwnedKey((string) $key)) {
                unset($runtimeVisual[$key]);
            }
        }

        $runtimeMaterial = array_filter($runtimeOverrides['material_config'] ?? [], fn ($v) => !is_null($v));
        foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
            unset($runtimeMaterial[$owned]);
        }

        $config['visual_config']   = array_merge(
            $config['visual_config']   ?? [],
            $runtimeVisual
        );
        $config['material_config'] = array_merge(
            $config['material_config'] ?? [],
            $runtimeMaterial
        );

        return $config;
    }

    private function planSees(string $visitorPlan, string $requiredPlan): bool
    {
        return match ($requiredPlan) {
            'free'    => true,
            'pro'     => in_array($visitorPlan, ['pro', 'studio']),
            'studio'  => $visitorPlan === 'studio',
            default   => true,
        };
    }

    private function planRank(string $plan): int
    {
        return match ($plan) {
            'pro'    => 1,
            'studio' => 2,
            default  => 0,
        };
    }
}

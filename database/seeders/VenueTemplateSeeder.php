<?php

namespace Database\Seeders;

use App\Models\VenueTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 built-in venue templates with FULL data-driven configuration
 * in the new JSON columns.
 *
 * The values in `visual_config`, `material_config`, `decorations`, and
 * `lighting_fixtures` mirror the values that were previously hardcoded in
 * resources/views/gallery/view.blade.php's `applyVenueOverrides(slug)`
 * switch statement. After running this seeder, the JS switch and the JSON
 * config produce IDENTICAL visual output — the switch can then be removed
 * (or kept as a fallback) without changing the look of any existing gallery.
 *
 * Run after the 2026_06_21_000001_extend_venue_templates_table migration:
 *
 *     php artisan db:seed --class=VenueTemplateSeeder
 *
 * Safe to re-run — uses updateOrCreate on the slug.
 */
class VenueTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $data) {
            VenueTemplate::updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            // ─────────────────────────────────────────────────────────────
            // 1. Modern White Cube — the default, free plan
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Modern White Cube',
                'slug'          => 'white-cube',
                'description'   => 'Minimal contemporary exhibition space. The professional standard for 2D work.',
                'category'      => 'gallery',
                'tags'          => ['contemporary', 'minimal', 'default'],
                'plan_required' => 'free',
                'capacity_min'  => 20,
                'capacity_max'  => 60,
                'sort_order'    => 1,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 4,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 4,
                    'background_color'       => '0x0f0f0f',
                    'fog_color'              => '0x0f0f0f',
                    'fog_near'               => 10,
                    'fog_far'                => 30,
                    'ambient_color'          => '0xffffff',
                    'ambient_intensity'      => 0.2,
                    'spot_intensity'         => 0.45,
                    'fill_intensity'         => 0.12,
                    'tone_mapping_exposure'  => 0.5,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 0.9,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.7,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.4,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'corridor', 'l-shape', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 2. Industrial Loft — Pro plan, concrete + steel + beams
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Industrial Loft',
                'slug'          => 'industrial-loft',
                'description'   => 'Concrete, steel and large open spaces. Urban contemporary feel with exposed ceiling beams.',
                'category'      => 'warehouse',
                'tags'          => ['urban', 'concrete', 'industrial'],
                'plan_required' => 'pro',
                'capacity_min'  => 30,
                'capacity_max'  => 80,
                'sort_order'    => 2,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'concrete',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'modern',
                    'room_layout'     => 'corridor',
                ],
                'visual_config' => [
                    'wall_height'            => 7,
                    'wall_depth'             => 0.5,
                    'ceiling_type'           => 'beamed',
                    'ceiling_height'         => 7,
                    'background_color'       => '0x111008',
                    'fog_color'              => '0x111008',
                    'fog_near'               => 8,
                    'fog_far'                => 35,
                    'ambient_color'          => '0xffd9a8',
                    'ambient_intensity'      => 0.18,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.15,
                    'tone_mapping_exposure'  => 0.55,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 1.0,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.8,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.9,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.7,
                ],
                'decorations' => [
                    [
                        'id'            => 'beam-1',
                        'type'          => 'custom',
                        'model_path'    => 'venue-models/industrial-beam.glb',
                        'position'      => [0, 6.5, 0],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1,
                        'plan_required' => 'free',
                    ],
                    [
                        'id'            => 'steel-column-1',
                        'type'          => 'column',
                        'model_path'    => 'venue-models/steel-column.glb',
                        'position'      => [-3, 0, -3],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1,
                        'plan_required' => 'free',
                    ],
                ],
                'lighting_fixtures' => [
                    [
                        'id'          => 'warm-pendant-1',
                        'type'        => 'point',
                        'position'    => [0, 6, 0],
                        'color'       => '0xffd9a8',
                        'intensity'   => 0.4,
                        'cast_shadow' => false,
                        'distance'    => 12,
                        'decay'       => 2,
                    ],
                ],
                'supported_layouts' => ['square', 'corridor', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 3. Dark Museum — Pro, dramatic, gold frames forced
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Dark Museum',
                'slug'          => 'dark-museum',
                'description'   => 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.',
                'category'      => 'museum',
                'tags'          => ['dramatic', 'premium', 'dark'],
                'plan_required' => 'pro',
                'capacity_min'  => 15,
                'capacity_max'  => 50,
                'sort_order'    => 3,
                'is_featured'   => false,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'brick',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 5,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 5,
                    'background_color'       => '0x020202',
                    'fog_color'              => '0x020202',
                    'fog_near'               => 5,
                    'fog_far'                => 18,
                    'ambient_color'          => '0xfff4e6',
                    'ambient_intensity'      => 0.15,
                    'spot_intensity'         => 0.55,
                    'fill_intensity'         => 0.08,
                    'tone_mapping_exposure'  => 0.5,
                    'frame_override'         => 'gold',
                ],
                'material_config' => [
                    'wall_color'             => '0x1a1a1a',
                    'wall_roughness'         => 0.85,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.6,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.3,
                    'floor_metalness'        => 0.2,
                    'floor_normal_strength'  => 0.5,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 4. Japanese Zen Gallery — Pro, low walls, natural materials
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Japanese Zen Gallery',
                'slug'          => 'zen-gallery',
                'description'   => 'Minimal architecture with natural materials. Calm and focused atmosphere with partial dividers.',
                'category'      => 'minimal',
                'tags'          => ['zen', 'natural', 'calm'],
                'plan_required' => 'pro',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 4,
                'is_featured'   => false,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'wood',
                    'floor_material'  => 'wood',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 3.2,
                    'wall_depth'             => 0.15,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 3.2,
                    'background_color'       => '0x1a1710',
                    'fog_color'              => '0x1a1710',
                    'fog_near'               => 12,
                    'fog_far'                => 40,
                    'ambient_color'          => '0xffe8c2',
                    'ambient_intensity'      => 0.22,
                    'spot_intensity'         => 0.45,
                    'fill_intensity'         => 0.14,
                    'tone_mapping_exposure'  => 0.55,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 0.7,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.5,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.7,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.6,
                ],
                'decorations' => [
                    [
                        'id'            => 'divider-1',
                        'type'          => 'custom',
                        'model_path'    => 'venue-models/zen-divider.glb',
                        'position'      => [0, 0, -2],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1,
                        'plan_required' => 'free',
                    ],
                ],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'rotunda', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 5. Luxury Penthouse — Studio, marble + gold frames
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Luxury Penthouse',
                'slug'          => 'luxury-penthouse',
                'description'   => 'High-end collector experience. Private gallery atmosphere with marble floors and gold accents.',
                'category'      => 'luxury',
                'tags'          => ['luxury', 'collector', 'private'],
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 5,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'l-shape',
                ],
                'visual_config' => [
                    'wall_height'            => 4.5,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 4.5,
                    'background_color'       => '0x08090d',
                    'fog_color'              => '0x08090d',
                    'fog_near'               => 8,
                    'fog_far'                => 25,
                    'ambient_color'          => '0xb8c8e8',
                    'ambient_intensity'      => 0.2,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.15,
                    'tone_mapping_exposure'  => 0.55,
                    'frame_override'         => 'gold',
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 0.8,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.3,
                    'floor_metalness'        => 0.2,
                    'floor_normal_strength'  => 0.5,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 6. Cyber Gallery — Studio, neon strips, futuristic
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Cyber Gallery',
                'slug'          => 'cyber-gallery',
                'description'   => 'Futuristic neon exhibition space. For digital and web3 creators.',
                'category'      => 'futuristic',
                'tags'          => ['cyberpunk', 'neon', 'digital', 'web3'],
                'plan_required' => 'studio',
                'capacity_min'  => 20,
                'capacity_max'  => 100,
                'sort_order'    => 6,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'concrete',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'modern',
                    'room_layout'     => 'corridor',
                ],
                'visual_config' => [
                    'wall_height'            => 6,
                    'wall_depth'             => 0.4,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 6,
                    'background_color'       => '0x020412',
                    'fog_color'              => '0x020412',
                    'fog_near'               => 6,
                    'fog_far'                => 22,
                    'ambient_color'          => '0x3060ff',
                    'ambient_intensity'      => 0.18,
                    'spot_intensity'         => 0.55,
                    'fill_intensity'         => 0.1,
                    'tone_mapping_exposure'  => 0.5,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => '0x0a0a14',
                    'wall_roughness'         => 0.6,
                    'wall_metalness'         => 0.3,
                    'wall_normal_strength'   => 0.5,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.4,
                    'floor_metalness'        => 0.5,
                    'floor_normal_strength'  => 0.5,
                ],
                'decorations' => [
                    [
                        'id'            => 'neon-strip-1',
                        'type'          => 'strip',
                        'model_path'    => 'venue-models/neon-strip-blue.glb',
                        'position'      => [0, 5.5, 0],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1,
                        'plan_required' => 'free',
                    ],
                ],
                'lighting_fixtures' => [
                    [
                        'id'          => 'neon-blue-1',
                        'type'        => 'strip',
                        'position'    => [0, 5.5, 0],
                        'color'       => '0x3060ff',
                        'intensity'   => 1.5,
                        'cast_shadow' => false,
                        'distance'    => 15,
                        'decay'       => 1.5,
                    ],
                ],
                'supported_layouts' => ['square', 'corridor'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 7. Outdoor Sculpture Garden — Studio, no ceiling, open-air
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Outdoor Sculpture Garden',
                'slug'          => 'sculpture-garden',
                'description'   => 'Open-air exhibition environment. For large installations and outdoor sculpture work.',
                'category'      => 'outdoor',
                'tags'          => ['outdoor', 'sculpture', 'open-air'],
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 100,
                'sort_order'    => 7,
                'is_featured'   => false,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'wood',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'l-shape',
                ],
                'visual_config' => [
                    'wall_height'            => 8,
                    'wall_depth'             => 0.6,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x0d1a0d',
                    'fog_color'              => '0x0d1a0d',
                    'fog_near'               => 10,
                    'fog_far'                => 45,
                    'ambient_color'          => '0xe0f0d0',
                    'ambient_intensity'      => 0.25,
                    'spot_intensity'         => 0.4,
                    'fill_intensity'         => 0.18,
                    'tone_mapping_exposure'  => 0.6,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => '0x6a7a5a',
                    'wall_roughness'         => 1.0,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.8,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.9,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.7,
                ],
                'decorations' => [
                    [
                        'id'            => 'pedestal-1',
                        'type'          => 'pedestal',
                        'model_path'    => 'venue-models/stone-pedestal.glb',
                        'position'      => [0, 0, 0],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1,
                        'plan_required' => 'free',
                    ],
                    [
                        'id'            => 'plant-1',
                        'type'          => 'plant',
                        'model_path'    => 'venue-models/garden-plant.glb',
                        'position'      => [-4, 0, -4],
                        'rotation'      => [0, 0, 0],
                        'scale'         => 1.2,
                        'plan_required' => 'free',
                    ],
                ],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'l-shape', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 8. Infinite Void — Studio, no walls/ceiling, no fog
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Infinite Void',
                'slug'          => 'infinite-void',
                'description'   => 'Floating artworks in an endless environment. No limits, no walls, no ceiling.',
                'category'      => 'abstract',
                'tags'          => ['abstract', 'infinite', 'floating'],
                'plan_required' => 'studio',
                'capacity_min'  => 1,
                'capacity_max'  => null,
                'sort_order'    => 8,
                'is_featured'   => false,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'brick',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 20,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x000000',
                    'fog_color'              => null,
                    'fog_near'               => 0,
                    'fog_far'                => 0,
                    'ambient_color'          => '0xa0b0d0',
                    'ambient_intensity'      => 0.2,
                    'spot_intensity'         => 0.55,
                    'fill_intensity'         => 0.12,
                    'tone_mapping_exposure'  => 0.55,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => '0x050505',
                    'wall_roughness'         => 0.95,
                    'wall_metalness'         => 0.1,
                    'wall_normal_strength'   => 0.4,
                    'floor_color'            => '0x0a0a0a',
                    'floor_roughness'        => 0.4,
                    'floor_metalness'        => 0.6,
                    'floor_normal_strength'  => 0.4,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['rotunda'],
            ],
        ];
    }
}

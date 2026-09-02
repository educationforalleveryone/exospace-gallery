<?php

namespace Database\Seeders;

use App\Models\VenueTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the venue templates with full data-driven configuration.
 *
 * After running this seeder, the JS switch in VenueDecorator.js is only a
 * fallback — the data-driven path (via VenueConfigExporter) takes precedence.
 *
 * Run:
 *   php artisan db:seed --class=VenueTemplateSeeder
 *
 * Safe to re-run — uses updateOrCreate on the slug.
 *
 * ⚠ PRODUCTION POLICY (Iteration 0, roadmap §15.13): updateOrCreate
 * OVERWRITES any super-admin edits to these 11 venue rows on every re-seed.
 * Once admins begin hand-tuning venues, run this seeder on FRESH INSTALLS
 * ONLY; apply ongoing copy/config changes via guarded migrations (which
 * update a row only when it still matches the previous seeded value) or
 * via the super-admin UI.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VENUE LIST (11 venues total)
 * Descriptions below are the Iteration 0 "Honesty" pass (roadmap P0.1):
 * every description is verifiable against the current render. When a venue's
 * render gains the promised capability (Iterations 2–3), re-tighten the copy
 * HERE and in a new guarded migration — never in JS maps (they were deleted).
 * ─────────────────────────────────────────────────────────────────────────────
 * FREE PLAN:
 *   1. white-cube          — Modern White Cube (default)
 *   2. infinite-void       — Vast dark space, dust, artworks in the round
 *
 * PRO PLAN:
 *   3. industrial-loft     — Concrete + steel + beams
 *   4. dark-museum         — Dramatic dark walls, gold frames
 *   5. zen-gallery         — Minimal, natural materials, warm calm
 *   6. crystal-cathedral   — Glass forms drifting in blue void
 *   7. nebula-drift        — Starfield + nebula cloud + cosmic feel
 *
 * STUDIO PLAN:
 *   8. luxury-penthouse    — Moody collector space, marble + gold
 *   9. cyber-gallery       — Dark futuristic space with neon accents
 *  10. sculpture-garden    — Outdoor garden with hedges, trees, sky
 *  11. mirror-lake         — Dark lake floor, moonlight, mist
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

    public static function templates(): array
    {
        return [
            // ─────────────────────────────────────────────────────────────
            // 1. Modern White Cube — Free
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
            // 2. Infinite Void — Free (user's favourite, so it's free)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Infinite Void',
                'slug'          => 'infinite-void',
                'description'   => 'A vast dark space with slowly drifting dust. Artworks presented in the round on easels — no walls, no ceiling.',
                'category'      => 'abstract',
                'tags'          => ['abstract', 'infinite', 'floating'],
                'plan_required' => 'free',
                'capacity_min'  => 1,
                'capacity_max'  => null,
                'sort_order'    => 2,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
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

            // ─────────────────────────────────────────────────────────────
            // 3. Industrial Loft — Pro
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
                'sort_order'    => 3,
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
                'decorations'       => [],  // beams + columns are procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'corridor', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 4. Dark Museum — Pro (FIXED: dividers now register collisions)
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
                'sort_order'    => 4,
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
                'decorations'       => [],  // dividers are procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 5. Japanese Zen Gallery — Pro
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Japanese Zen Gallery',
                'slug'          => 'zen-gallery',
                'description'   => 'Minimal architecture with natural wood finishes and calm, warm light. A quiet, focused atmosphere.',
                'category'      => 'minimal',
                'tags'          => ['zen', 'natural', 'calm'],
                'plan_required' => 'pro',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 5,
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
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'rotunda', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 6. Crystal Cathedral — Pro (NEW void-style venue)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Crystal Cathedral',
                'slug'          => 'crystal-cathedral',
                'description'   => 'Crystalline forms drift through a deep blue void, lit by shifting colour. An ethereal, open exhibition space.',
                'category'      => 'abstract',
                'tags'          => ['glass', 'crystal', 'ethereal', 'refraction'],
                'plan_required' => 'pro',
                'capacity_min'  => 5,
                'capacity_max'  => 40,
                'sort_order'    => 6,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 12,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x0a0a1a',
                    'fog_color'              => '0x0a0a1a',
                    'fog_near'               => 15,
                    'fog_far'                => 50,
                    'ambient_color'          => '0xddeeff',
                    'ambient_intensity'      => 0.25,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.15,
                    'tone_mapping_exposure'  => 0.6,
                    'frame_override'         => 'silver',
                ],
                'material_config' => [
                    'wall_color'             => '0x202030',
                    'wall_roughness'         => 0.2,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.1,    // highly polished
                    'floor_metalness'        => 0.4,
                    'floor_normal_strength'  => 0.3,
                ],
                'decorations'       => [],  // glass shards are procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 7. Nebula Drift — Pro (NEW void-style venue)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Nebula Drift',
                'slug'          => 'nebula-drift',
                'description'   => 'Drift through a cosmic cloud of stars and purple nebula. For digital art and otherworldly exhibitions.',
                'category'      => 'abstract',
                'tags'          => ['cosmic', 'stars', 'nebula', 'ethereal'],
                'plan_required' => 'pro',
                'capacity_min'  => 5,
                'capacity_max'  => 50,
                'sort_order'    => 7,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 15,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x050015',
                    'fog_color'              => '0x050015',
                    'fog_near'               => 10,
                    'fog_far'                => 40,
                    'ambient_color'          => '0x8844ff',
                    'ambient_intensity'      => 0.2,
                    'spot_intensity'         => 0.55,
                    'fill_intensity'         => 0.15,
                    'tone_mapping_exposure'  => 0.6,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => '0x080015',
                    'wall_roughness'         => 0.4,
                    'wall_metalness'         => 0.2,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => '0x100525',
                    'floor_roughness'        => 0.3,
                    'floor_metalness'        => 0.5,
                    'floor_normal_strength'  => 0.3,
                ],
                'decorations'       => [],  // starfield + nebula particles are procedural
                'lighting_fixtures' => [
                    [
                        'id'          => 'nebula-center',
                        'type'        => 'point',
                        'position'    => [0, 5, 0],
                        'color'       => '0x8844ff',
                        'intensity'   => 0.5,
                        'cast_shadow' => false,
                        'distance'    => 30,
                        'decay'       => 1.5,
                    ],
                ],
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 8. Luxury Penthouse — Studio
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Luxury Penthouse',
                'slug'          => 'luxury-penthouse',
                'description'   => 'A moody, intimate collector space. Dark walls, marble floors, gold accents.',
                'category'      => 'luxury',
                'tags'          => ['luxury', 'collector', 'private'],
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 8,
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
            // 9. Cyber Gallery — Studio
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Cyber Gallery',
                'slug'          => 'cyber-gallery',
                'description'   => 'A dark futuristic exhibition space with neon light accents. For digital and web3 creators.',
                'category'      => 'futuristic',
                'tags'          => ['cyberpunk', 'neon', 'digital', 'web3'],
                'plan_required' => 'studio',
                'capacity_min'  => 20,
                'capacity_max'  => 100,
                'sort_order'    => 9,
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
                'decorations'       => [],  // neon strips are procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'corridor'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 10. Outdoor Sculpture Garden — Studio (REDESIGNED)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Outdoor Sculpture Garden',
                'slug'          => 'sculpture-garden',
                'description'   => 'Open-air garden exhibition. Hedges, trees, sky, and stone paths. Artworks on easels along a winding path.',
                'category'      => 'outdoor',
                'tags'          => ['outdoor', 'garden', 'sculpture', 'open-air'],
                'plan_required' => 'studio',
                'capacity_min'  => 5,
                'capacity_max'  => 30,
                'sort_order'    => 10,
                'is_featured'   => false,
                'version'       => '2.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'grass',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 0,    // no walls
                    'wall_depth'             => 0,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x87ceeb',  // sky blue (overridden by sky dome shader)
                    'fog_color'              => null,         // no fog — open air
                    'fog_near'               => 0,
                    'fog_far'                => 0,
                    'ambient_color'          => '0xe0f0ff',
                    'ambient_intensity'      => 0.4,          // brighter — outdoor daylight
                    'spot_intensity'         => 0.3,
                    'fill_intensity'         => 0.2,
                    'tone_mapping_exposure'  => 0.7,
                    'frame_override'         => null,
                ],
                'material_config' => [
                    'wall_color'             => null,         // n/a — no walls
                    'wall_roughness'         => 1.0,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.5,
                    'floor_color'            => '0x3a6a2a',   // grass green fallback
                    'floor_roughness'        => 1.0,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.9,
                ],
                'decorations'       => [],  // hedges, trees, sky, path are procedural (VenueDecorator)
                'lighting_fixtures' => [],  // sun is procedural (VenueDecorator)
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 11. Mirror Lake — Studio (NEW void-style venue)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Mirror Lake',
                'slug'          => 'mirror-lake',
                'description'   => 'A still, dark lake floor beneath soft mist and moonlight. Quiet, spacious, meditative.',
                'category'      => 'abstract',
                'tags'          => ['mirror', 'reflection', 'moonlit', 'meditative'],
                'plan_required' => 'studio',
                'capacity_min'  => 5,
                'capacity_max'  => 40,
                'sort_order'    => 11,
                'is_featured'   => true,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'moody',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 0,    // no walls
                    'wall_depth'             => 0,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x0a0a18',  // deep night
                    'fog_color'              => '0x0a0a18',
                    'fog_near'               => 15,
                    'fog_far'                => 45,
                    'ambient_color'          => '0xb0c8ff',
                    'ambient_intensity'      => 0.18,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.12,
                    'tone_mapping_exposure'  => 0.55,
                    'frame_override'         => 'silver',
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 1.0,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => '0x202830',
                    'floor_roughness'        => 0.0,    // perfect mirror
                    'floor_metalness'        => 1.0,
                    'floor_normal_strength'  => 0.1,
                ],
                'decorations'       => [],  // moon + mist particles are procedural
                'lighting_fixtures' => [
                    [
                        'id'          => 'moonlight',
                        'type'        => 'directional',
                        'position'    => [12, 22, -8],
                        'color'       => '0xb0c8ff',
                        'intensity'   => 0.6,
                        'cast_shadow' => false,
                    ],
                ],
                'supported_layouts' => ['rotunda'],
            ],
        ];
    }
}

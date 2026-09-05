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
 * VENUE LIST (12 venues total)
 *
 * Iteration 8 "The Salon" (roadmap P3.2): venue #12 is the catalog's first
 * pipeline-born venue (docs/VENUE_12_BRIEF.md decision record — the
 * pre-committed rule's no-data branch fired, building INTIMACY). Its
 * placement block (density intimate + orientation pairing) is the first
 * seeded USE of the IT6 curation machinery — as venue character, declared
 * here, never auto-magic (DO NOT DO #6). Zero slug-keyed JS exists to
 * know about it: the descriptor interpreter renders it as-is (§10.2).
 * Descriptions below are the Iteration 0 "Honesty" pass (roadmap P0.1):
 * every description is verifiable against the current render. When a venue's
 * render gains the promised capability (Iterations 2–3), re-tighten the copy
 * HERE and in a new guarded migration — never in JS maps (they were deleted).
 * ─────────────────────────────────────────────────────────────────────────────
 * Iteration 3 "Rooms" (roadmap P1.3): zen/penthouse/cyber carry structure
 * descriptors (StructureBuilder vocabulary), white-cube gates its respect
 * pass, the garden declares sun shadows, the museum default wall is
 * painted. Production rows are updated by the GUARDED migration
 * 2026_09_01_000003 — this seeder is the fresh-install baseline.
 *
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
 *   8. the-salon           — Close-hung warmth, domestic scale (Iteration 8)
 *
 * STUDIO PLAN:
 *   9. luxury-penthouse    — Moody collector space, marble + gold
 *  10. cyber-gallery       — Dark futuristic space with neon accents
 *  11. sculpture-garden    — Outdoor garden with hedges, trees, sky
 *  12. mirror-lake         — Dark lake floor, moonlight, mist
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
            //
            // WHITE CUBE POLISH iteration (forensic audit): re-tuned from
            // screenshots, not taste. The old row rendered a near-black room
            // wearing the white cube's name:
            //   • fog 0x0f0f0f (void-black) from 10→30 m sooted every far wall;
            //   • exposure 0.5 halved an already-weak rig (point intensities
            //     predate three's physical light units — r155+ — and read
            //     ~10× too dim);
            //   • fill_intensity was dead config (stored, never read — now
            //     wired through Lighting.venueFillIntensity);
            //   • bloom halos on a venue whose identity is calm neutrality.
            // Fog now dissolves toward gallery white, the rig is declared in
            // physical units, post-fx restraint is explicit, and the floor
            // reads as sealed polished concrete instead of wet cement.
            // Production rows are updated by the GUARDED migration
            // 2026_09_02_000001_white_cube_polish — this seeder stays the
            // fresh-install baseline.
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
                'version'       => '1.1.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'concrete',
                    'lighting_preset'  => 'bright',
                    // Thin charcoal frames (museum standard): on a white wall
                    // a white minimal frame dissolves and the hang loses its
                    // edge definition. Still visitor-overridable per gallery.
                    'frame_style'     => 'modern',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 4,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_height'         => 4,
                    // Gallery-white atmosphere: the room dissolves into
                    // light at distance — never into soot.
                    'background_color'       => '0xf2f1ee',
                    'fog_color'              => '0xf2f1ee',
                    'fog_near'               => 16,
                    'fog_far'                => 60,
                    'ambient_color'          => '0xffffff',
                    'ambient_intensity'      => 0.55,
                    'spot_intensity'         => 3.2,
                    'fill_intensity'         => 2.6,
                    'tone_mapping_exposure'  => 1.05,
                    'frame_override'         => null,
                    // Iteration 6 "Consolidation": interpreter selector for the respect
                    // pass (base reveal, crown line, visible ceiling fixtures) — was
                    // 'rooms' + a slug gate pre-IT6, now an explicit selector.
                    'structure_pass'        => 'cube',
                    // Post-processing identity: a white cube is calm. Bloom
                    // halos around every fixture read as spectacle, not
                    // exhibition; the vignette stays, softened.
                    'post_fx'                => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_darkness' => 0.28,
                        'vignette_offset'   => 1.05,
                    ],
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 0.9,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    // Sealed polished concrete — lighter and slightly
                    // reflective so the floor bounces the rig instead of
                    // swallowing it.
                    'floor_color'            => '0x9c9c98',
                    'floor_roughness'        => 0.55,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.4,
                    'floor_tile_meters'      => 2.0,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'corridor', 'l-shape', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 2. Infinite Void — Free (user's favourite, so it's free)
            //
            // Iteration "Void Deepening": the rig numbers are the post-polish
            // physical-units values (the IT0 numbers rendered artworks at
            // ~10% — a void whose only bright contents were its frames).
            // Production rows are updated by the GUARDED migration
            // 2026_09_05_000001_infinite_void_deepening — this seeder stays
            // the fresh-install baseline.
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Infinite Void',
                'slug'          => 'infinite-void',
                // Iteration 2 "Phenomena": copy re-tightened — float placement
                // now makes the original promise literally true (§4.2).
                'description'   => 'Weightless artworks float in an endless dark, dust drifting slowly around them. No walls, no ceiling, no horizon.',
                'category'      => 'abstract',
                'tags'          => ['abstract', 'infinite', 'floating'],
                'plan_required' => 'free',
                'capacity_min'  => 1,
                'capacity_max'  => null,
                'sort_order'    => 2,
                'is_featured'   => true,
                'version'       => '2.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset'  => 'dramatic',
                    // Thin charcoal frames: white frames glare in a black
                    // void (and were the only thing the inherited bloom pass
                    // had to eat). Still visitor-overridable per gallery.
                    'frame_style'     => 'modern',
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
                    'ambient_intensity'      => 0.3,
                    'spot_intensity'         => 1.3,
                    'fill_intensity'         => 0.2,
                    'tone_mapping_exposure'  => 0.9,
                    'frame_override'         => null,
                    // ── Iteration 2 "Phenomena" declared identity ──────
                    'placement_mode'  => 'float',  // §10.5 — the original promise, now real
                    'floor_edge_fade'  => true,  // §4.2 — the "endless" must read
                    'env_intensity'  => 0,  // a pure void — no preset HDRI horizon glow
                    'structure_pass'  => 'phenomena',  // per-venue rollback switch
                    // ── Void Deepening declared identity ────────────────
                    // Artworks on a ring sit beyond the proximity radius —
                    // they carry a standing glow of their own (Lighting.js
                    // reads the fraction; wall venues keep the 0.15 default).
                    'artwork_light_base' => 0.45,
                    // A 12-piece hang lights every piece at once (pool cap;
                    // Lighting.js — tier floors still apply).
                    'artwork_light_pool_cap' => 12,
                    // Frame edges dissolve to BLACK (darkness 1.0 targets
                    // zero — the only mix target that survives ACES tone
                    // mapping as true black) — the void reads infinite,
                    // never enclosed in the default grey ring. Bloom off:
                    // nothing in a void should halo.
                    'post_fx'         => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_darkness' => 1.0,
                        'vignette_offset'   => 1.35,
                    ],
                    // §7 presentation language: collections past 12 works
                    // compose in TWO depth rings (PlacementMath + RoomBuilder
                    // interpret the key generically; small shows stay calm).
                    'placement'       => ['depth_bands' => 2],
                    // The zenith depth cue — near-black blue above, pure
                    // black at the horizon; makes the dark read as DISTANCE.
                    'void_depth_gradient' => true,
                    // Iteration 6 consolidation keys (declare the shell + composable
                    // phenomena — replaces the CIRCULAR/OPEN_AIR slug sets):
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    'void_dust'       => true,  // slow-floating dust field
                ],
                'material_config' => [
                    'wall_color'             => '0x050505',
                    'wall_roughness'         => 0.95,
                    'wall_metalness'         => 0.1,
                    'wall_normal_strength'   => 0.4,
                    'floor_color'            => '0x0a0a0a',
                    'floor_roughness'        => 0.32,
                    'floor_metalness'        => 0.25,
                    'floor_normal_strength'  => 0.4,
                    // Declared colours are authoritative over textures
                    // (Materials.js tint path) — production marble obeys the
                    // obsidian declaration instead of ignoring it.
                    'texture_tint'           => true,
                ],
                'decorations'       => [],
                'lighting_fixtures' => [],
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 3. Industrial Loft — Pro
            //
            // INDUSTRIAL LOFT DEEPENING iteration (forensic audit):
            // screenshot-verified defects in the v1.0.0 row — every fix
            // mirrored in the GUARDED migration
            // 2026_09_06_000001_industrial_loft_deepening:
            //   • The rig was pre-polish (exposure 0.55, ambient 0.18,
            //     spot 0.5, fill 0.15 — the exact profile the White Cube
            //     audit measured at ~10× too dim under r155+ physical
            //     units). The venue rendered as a near-black tunnel whose
            //     only bright contents were two track-light dots. The rig
            //     is now declared in physical units while keeping the warm
            //     industrial mood (ambient 0.55, spot 2.4, fill 1.1,
            //     exposure 0.9), and the fog reach grew with it
            //     (8→35 m squeezed a 16 m+ room into murk; 14→55 m).
            //   • post_fx was never declared → the runtime default put BLOOM
            //     ON in a venue of emissive lamps and night glass. Restraint
            //     is now explicit (bloom off, softened vignette).
            //   • The venue was dark AND its artworks carried only the
            //     generic 0.15 standing glow: artwork_light_base 0.22 +
            //     pool cap 12 so a real hang reads lit, not spotlighted
            //     islands in the gloom.
            //   • frame_override 'black': blackened-steel frames — the
            //     white 'modern' default dissolved against concrete.
            //   • floor_tile_meters 3.0 (screed pour scale), floor
            //     roughness 0.9→0.8 (sealed, not wet cement), normal
            //     strengths eased (0.8/0.7 → 0.65/0.6) so the production
            //     Concrete033 maps don't buzz at walking distance.
            //   • default room_layout: corridor → square. The venue's own
            //     copy promises "large open spaces" at 30–80 works; the
            //     corridor default produced a 6 m × 108 m linear tunnel
            //     at capacity whose far half sat beyond the fog. The
            //     square floor keeps the promise (corridor remains a
            //     supported layout for smaller shows; corridor_width 9
            //     gives the aisle real loft proportions).
            //   • JS-side identity (same iteration, no migration): joists
            //     under the declared girders, visible stanchions, floor
            //     coves and clerestory windows measured from the wall
            //     FACE, spawn-safe props, pendant fixture parity — see
            //     VenueDecorator.addIndustrialLoftStructure.
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Industrial Loft',
                'slug'          => 'industrial-loft',
                'description'   => 'A converted warehouse floor: raw concrete, black steel and a seven-metre ceiling. Exposed girders and joists overhead, steel stanchions, glowing factory windows in the clerestory, pendant lamps and an open concrete run for the hang.',
                'category'      => 'warehouse',
                'tags'          => ['urban', 'concrete', 'industrial'],
                'plan_required' => 'pro',
                'capacity_min'  => 30,
                'capacity_max'  => 80,
                'sort_order'    => 3,
                'is_featured'   => true,
                'version'       => '2.0.0',
                'default_settings' => [
                    'wall_texture'    => 'concrete',
                    'floor_material'  => 'concrete',
                    'lighting_preset'  => 'dramatic',
                    'frame_style'     => 'modern',
                    // Deepening: the open floor is the venue's identity (see
                    // the block comment) — corridor stays a supported layout.
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 7,
                    'wall_depth'             => 0.5,
                    'ceiling_type'           => 'beamed',
                    'ceiling_color'          => '0x1a1a18',  // was the per-slug ceiling chain
                    'ceiling_beams'          => true,        // primary girders (RoomBuilder)
                    'ceiling_height'         => 7,
                    // Loft proportions: the corridor aisle widens from the
                    // generic 6 m (RoomBuilder default) to a real floor span.
                    'corridor_width'         => 9,
                    'background_color'       => '0x111008',
                    'fog_color'              => '0x111008',
                    'fog_near'               => 14,
                    'fog_far'                => 55,
                    'ambient_color'          => '0xffd9a8',
                    'structure_pass'         => 'loft',  // interpreter selector (joists,
                                                            // stanchions, coves, clerestory,
                                                            // pendants, props)
                    'ambient_intensity'      => 0.55,
                    'spot_intensity'         => 2.4,
                    'fill_intensity'         => 1.1,
                    'tone_mapping_exposure'  => 0.9,
                    // Blackened-steel frames — visible against raw concrete.
                    'frame_override'         => 'black',
                    // Dark-venue artwork legibility: every piece carries a
                    // standing glow, and a typical hang lights at once
                    // (Lighting.js pool cap — tier floors still apply).
                    'artwork_light_base'     => 0.22,
                    'artwork_light_pool_cap' => 12,
                    // Night HDRI at reduced strength: enough for the steel
                    // girders to catch a highlight, not enough to grey the
                    // murk (unset = the dramatic preset's 0.30).
                    'env_intensity'          => 0.25,
                    // Post-processing identity: a working warehouse, not a
                    // neon sign — bloom off, gentle vignette keeps the mood.
                    'post_fx'                => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_darkness' => 0.35,
                        'vignette_offset'   => 1.0,
                    ],
                ],
                'material_config' => [
                    'wall_color'             => null,
                    'wall_roughness'         => 1.0,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.65,
                    'floor_color'            => null,
                    'floor_roughness'        => 0.8,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.6,
                    // Power-trowelled slabs read at pour scale, not tile scale.
                    'floor_tile_meters'      => 3.0,
                ],
                'decorations'       => [],  // joists, stanchions, coves, clerestory,
                                            // pendants and props are procedural
                                            // (VenueDecorator 'loft' pass)
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
                    // Iteration 3 (§4.4): brick reads loft — the venue default becomes a
                    // painted museum wall under the dark tint (guarded migration mirrors).
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset'  => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 5,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x080808',  // was the per-slug ceiling chain
                    'ceiling_height'         => 5,
                    'background_color'       => '0x020202',
                    'fog_color'              => '0x020202',
                    'fog_near'               => 5,
                    'fog_far'                => 18,
                    'ambient_color'          => '0xfff4e6',
                    'structure_pass'         => 'museum',  // IT6: interpreter selector (dividers,
                                                            // cap + hangable faces, skirting)
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
                // Iteration 3 "Rooms": copy re-tightened — shoji screens, the tokonoma
                // alcove and the bench now exist (guarded migration mirrors this).
                'description'   => 'A quiet, focused space: shoji screens, a tokonoma alcove and warm wood, tuned for close, calm looking.',
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
                    'lighting_preset'  => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 3.2,
                    'wall_depth'             => 0.15,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x1e1c14',  // was the per-slug ceiling chain
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
                    // ── Iteration 3 "Rooms" declared identity (§10.3 interpreter; rollback =
                    // remove these keys — the venue reverts to its pre-pass render, live).
                    'structure_pass'        => 'rooms',
                    'structure'              => [
            // ── Shoji screen, panel A — wood frame strips around translucent
            // paper (the paper is the colliding surface; frames are trim).
            ['id' => 'shoji-a-top',       'primitive' => 'box',   'at' => [1.9, 2.065, -0.55], 'size' => [0.06, 0.09, 1.15], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-a-bottom',    'primitive' => 'box',   'at' => [1.9, 0.055, -0.55], 'size' => [0.06, 0.09, 1.15], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-a-end-near',  'primitive' => 'box',   'at' => [1.9, 1.06, -1.085], 'size' => [0.06, 2.12, 0.08], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-a-end-far',   'primitive' => 'box',   'at' => [1.9, 1.06, -0.015], 'size' => [0.06, 2.12, 0.08], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-a-stile',     'primitive' => 'box',   'at' => [1.9, 1.06, -0.55],  'size' => [0.045, 1.94, 0.05], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-a-paper',     'primitive' => 'plane', 'at' => [1.9, 1.06, -0.55],  'rot' => [0, 1.5707963, 0], 'size' => [1.0, 1.9], 'material' => 'paper_shoji', 'collide' => true],
            // ── Shoji screen, panel B (the pair reads as one partial divider
            // with a slit — "partial dividers", verbatim §4.5).
            ['id' => 'shoji-b-top',       'primitive' => 'box',   'at' => [1.9, 2.065, 0.65],  'size' => [0.06, 0.09, 1.15], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-b-bottom',    'primitive' => 'box',   'at' => [1.9, 0.055, 0.65],  'size' => [0.06, 0.09, 1.15], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-b-end-near',  'primitive' => 'box',   'at' => [1.9, 1.06, 0.115],  'size' => [0.06, 2.12, 0.08], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-b-end-far',   'primitive' => 'box',   'at' => [1.9, 1.06, 1.185],  'size' => [0.06, 2.12, 0.08], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-b-stile',     'primitive' => 'box',   'at' => [1.9, 1.06, 0.65],   'size' => [0.045, 1.94, 0.05], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'shoji-b-paper',     'primitive' => 'plane', 'at' => [1.9, 1.06, 0.65],   'rot' => [0, 1.5707963, 0], 'size' => [1.0, 1.9], 'material' => 'paper_shoji', 'collide' => true],
            // ── Tokonoma alcove — raised platform, framed back panel, hanging
            // scroll, stone. Off-centre by design (asymmetry is the form).
            ['id' => 'alcove-platform',   'primitive' => 'box',    'at' => [1.9, 0.08, -2.15],  'size' => [1.7, 0.16, 0.95], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'zen-wood'],
            ['id' => 'alcove-back',       'primitive' => 'box',    'at' => [1.9, 1.125, -2.66], 'size' => [1.7, 2.25, 0.06], 'material' => 'wood_dark', 'collide' => true],
            ['id' => 'alcove-jamb-l',     'primitive' => 'box',    'at' => [1.09, 1.15, -2.63], 'size' => [0.09, 2.3, 0.09], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'alcove-jamb-r',     'primitive' => 'box',    'at' => [2.71, 1.15, -2.63], 'size' => [0.09, 2.3, 0.09], 'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'alcove-lintel',     'primitive' => 'box',    'at' => [1.9, 2.33, -2.63],  'size' => [1.78, 0.1, 0.1],  'material' => 'wood_dark', 'merge' => 'zen-frames'],
            ['id' => 'alcove-scroll',     'primitive' => 'plane',  'at' => [1.9, 1.55, -2.615], 'size' => [0.52, 1.25], 'material' => 'plaster_warm'],
            ['id' => 'alcove-stone',      'primitive' => 'sphere', 'at' => [1.62, 0.36, -2.1],  'size' => [0.4, 0.4, 0.4], 'material' => 'stone', 'merge' => 'zen-stone'],
            // ── Low horizontal datum — the bench (§4.5).
            ['id' => 'bench-top',         'primitive' => 'box', 'at' => [1.9, 0.42, 1.55], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'zen-bench'],
            ['id' => 'bench-leg-l',       'primitive' => 'box', 'at' => [1.25, 0.19, 1.55], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'zen-bench'],
            ['id' => 'bench-leg-r',       'primitive' => 'box', 'at' => [2.55, 0.19, 1.55], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'zen-bench'],
        ],
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
                'description'   => 'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.',
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
                    'lighting_preset'  => 'bright',
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
                    // ── Iteration 2 "Phenomena" declared identity ──────
                    'placement_mode'  => 'float',  // §10.5
                    'glass_material'  => 'transmission',  // §11.3 row 2 — tier-resolved (never null glass)
                    'colonnade_tint'  => '0xdfeaff',  // glass hue, interpreter-generic
                    'structure_pass'  => 'phenomena',  // per-venue rollback switch
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    'void_colonnade'  => true,   // seeded glass colonnade (new body);
                                                 // swap to void_shards for the rollback body
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
                'description'   => 'Artworks drift through a cosmic cloud — distant stars and a purple nebula with quiet depth between them. For digital art and otherworldly exhibitions.',
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
                    'lighting_preset'  => 'dramatic',
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
                    // ── Iteration 2 "Phenomena" declared identity ──────
                    'placement_mode'  => 'float',  // §4.7 — "drift", not "stand"
                    'env_intensity'  => 0.05,  // night.hdr horizon glow silenced
                    'structure_pass'  => 'phenomena',  // starfield fog exemption
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    'void_starfield'  => true,   // starfield + nebula cloud, fog-exempt
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
                        'cast_shadow'  => false,
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
                // Iteration 3 "Rooms": copy re-tightened — glazing, skyline and lounge now
                // render (§4.8; guarded migration mirrors this).
                'description'   => 'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.',
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
                    'lighting_preset'  => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'l-shape',
                ],
                'visual_config' => [
                    'wall_height'            => 4.5,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x080808',  // was the per-slug ceiling chain
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
                    // ── Iteration 3 "Rooms" declared identity: glazing wall + descriptors.
                    'structure_pass'        => 'rooms',
                    'glazing_wall'          => true,
                    'structure'              => [
            // ── Terrace deck just outside the glazing (towers rise from it).
            ['id' => 'terrace-deck',   'primitive' => 'box',            'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'dark_trim'],
            // ── The glazing itself: tier-resolved glass + steel mullions.
            ['id' => 'glazing-glass',  'primitive' => 'plane',          'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.4], 'material' => ['glass' => true, 'tint' => '0xc4d8ea', 'opacity' => 0.18]],
            ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.4, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 4.42, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            // ── Terrace rail line (inside the glass, §4.8).
            ['id' => 'rail-bar',       'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts',     'primitive' => 'instance-grid',  'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            // ── Night city skyline: distant emissive towers, seeded per
            // gallery (deterministic §13.6), grounded on the terrace line.
            ['id' => 'skyline-cool',   'primitive' => 'instance-grid',  'size' => [2.2, 10, 2.2], 'material' => 'tower_cool', 'grid' => ['mode' => 'scatter', 'count' => 9, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 5], 'scale_jitter' => [0.55, 2.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-warm',   'primitive' => 'instance-grid',  'size' => [1.5, 14, 1.5], 'material' => 'tower_warm', 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 9], 'scale_jitter' => [0.5, 1.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            // ── Warm pendant over the lounge (visual pool; no new dynamic
            // light — the pooled-light budget is untouched, PERF-B18).
            ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 3.55, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
            // ── Lounge group: rug, sofa (base + back + arms), low table.
            ['id' => 'lounge-rug',     'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]],  'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.2, 2.3], 'material' => 'fabric_dark'],
            ['id' => 'sofa-base',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]],  'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
            ['id' => 'sofa-back',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]],  'turn' => 'in', 'size' => [2.3, 0.5, 0.24],  'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-l',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-r',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]],  'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'table-top',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]],   'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
            ['id' => 'table-pedestal', 'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]],   'turn' => 'in', 'size' => [0.5, 0.3, 0.35],  'material' => 'dark_trim', 'collide' => true],
        ],
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
                // Iteration 3 "Rooms": copy re-tightened — perimeter neon + floor light
                // grid now render, bloom-off safe (§4.9; guarded migration mirrors).
                'description'   => 'A dark electric space ringed with neon on every edge, the floor traced in light. For digital and web3 creators.',
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
                    'lighting_preset'  => 'dramatic',
                    'frame_style'     => 'modern',
                    'room_layout'     => 'corridor',
                ],
                'visual_config' => [
                    'wall_height'            => 6,
                    'wall_depth'             => 0.4,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x04081a',  // was the per-slug ceiling chain
                    'ceiling_neon'           => true,        // two-strip rollback body (renders
                                                             // only when structure_pass ≠ 'rooms')
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
                    // ── Iteration 3 "Rooms" declared identity: perimeter neon + light grid,
                    // readable with bloom OFF (the legacy two-strip ceiling is skipped when
                    // structure_pass is declared).
                    'structure_pass'        => 'rooms',
                    'structure'              => [
            // ── Perimeter neon, ALL FOUR edges (§4.9 — the old two strips ran
            // along one axis only). Ceiling-junction mounted, up:'ceiling'
            // keeps them at the top whatever wall height the admin sets.
            ['id' => 'neon-top-front', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'offset' => [0, -0.05, 0.08], 'up' => 'ceiling'], 'turn' => 'in', 'fit' => 'wall', 'size' => [1, 0.055, 0.055], 'material' => 'neon_cyan', 'merge' => 'cy-cyan'],
            ['id' => 'neon-top-back',  'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_back',  'offset' => [0, -0.05, 0.08], 'up' => 'ceiling'], 'turn' => 'in', 'fit' => 'wall', 'size' => [1, 0.055, 0.055], 'material' => 'neon_cyan', 'merge' => 'cy-cyan'],
            ['id' => 'neon-top-left',  'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left',  'offset' => [0, -0.05, 0.08], 'up' => 'ceiling'], 'turn' => 'in', 'fit' => 'wall', 'size' => [1, 0.055, 0.055], 'material' => 'neon_magenta', 'merge' => 'cy-magenta'],
            ['id' => 'neon-top-right', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'offset' => [0, -0.05, 0.08], 'up' => 'ceiling'], 'turn' => 'in', 'fit' => 'wall', 'size' => [1, 0.055, 0.055], 'material' => 'neon_magenta', 'merge' => 'cy-magenta'],
            // ── Floor light grid: cross lines every 2.4 m spanning the room
            // (fit:'area_z' tracks the room), plus perimeter floor rails.
            ['id' => 'floor-grid',     'primitive' => 'instance-grid',  'at' => [0, 0.012, 0], 'size' => [0.05, 0.02, 1], 'fit' => 'area_z', 'material' => 'neon_cyan', 'merge' => 'cy-floor', 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.012, 'size' => ['fit' => 'room', 'pad' => [1.2, 0.9]]], 'spacing' => [2.4, 0, 0]]],
            ['id' => 'floor-rail-front', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.012, 0.5]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.4, 'size' => [1, 0.02, 0.05], 'material' => 'neon_magenta', 'merge' => 'cy-floor'],
            ['id' => 'floor-rail-back',  'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_back',  'offset' => [0, 0.012, 0.5]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.4, 'size' => [1, 0.02, 0.05], 'material' => 'neon_magenta', 'merge' => 'cy-floor'],
            ['id' => 'floor-rail-left',  'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left',  'offset' => [0, 0.012, 0.5]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.4, 'size' => [1, 0.02, 0.05], 'material' => 'neon_magenta', 'merge' => 'cy-floor'],
            ['id' => 'floor-rail-right', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.012, 0.5]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.4, 'size' => [1, 0.02, 0.05], 'material' => 'neon_magenta', 'merge' => 'cy-floor'],
        ],
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
                    'lighting_preset'  => 'bright',
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
                    // IT6 consolidation keys — replace the OPEN_AIR / CIRCULAR
                    // slug sets and select the bespoke garden interpreter:
                    'open_air'               => true,
                    'layout_shape'           => 'circular',
                    'structure_pass'         => 'garden',
                    'ambient_intensity'      => 0.4,          // brighter — outdoor daylight
                    'spot_intensity'         => 0.3,
                    'fill_intensity'         => 0.2,
                    'tone_mapping_exposure'  => 0.7,
                    'frame_override'         => null,
                    // Iteration 3 (§4.10): the garden is the only venue whose sky establishes a
                    // sun — high-tier-only sun shadows, config-gated (rollback: remove key).
                    'sun_shadows'           => true,
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
                    'floor_tile_meters'     => 2.0,
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
                'description'   => 'A still, dark lake reflects the floating artworks and the moon. Mist drifts low. Quiet, spacious, meditative.',
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
                    'lighting_preset'  => 'moody',
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
                    // ── Iteration 2 "Phenomena" declared identity ──────
                    'placement_mode'  => 'float',  // artworks above the lake
                    'floor_reflection'  => 'planar',  // §11.3 row 1 — reflector high / gloss mood mobile
                    'env_intensity'  => 0.15,  // calm the moody preset's evening glow
                    'structure_pass'  => 'phenomena',  // per-venue rollback switch (name gate kept: Mirror Lake)
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    'void_lake'       => true,   // moon + reflection + mist composition
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
                        'cast_shadow'  => false,
                    ],
                ],
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 12. The Salon — Pro (Iteration 8, roadmap P3.2)
            // ─────────────────────────────────────────────────────────────
            // The catalog's first pipeline-born venue (§16.7: brief →
            // descriptors → preview → publish). Family: Room. One idea:
            // close-hung warmth. Placement block = the first seeded USE of
            // the IT6 curation machinery, as DECLARED venue character
            // (density 'intimate' ≈ 2.8 m rhythm + §6.4 orientation
            // pairing). No focal wall: hierarchy stays "carefully" — every
            // wall reads equal (the brief assigns focal treatment to the
            // grand hall, not the salon). Structure is domestic-scale and
            // anchor-based (wall-fit picture rail, centre bench, rug), so
            // it adapts to any square room the admin configures.
            [
                'name'          => 'The Salon',
                'slug'          => 'the-salon',
                'description'   => 'A small, warm room in the domestic tradition: works hung close together at conversational distance, a wooden picture rail and a bench, under soft warm light. Made for studies, prints, photography and portrait formats.',
                'category'      => 'classic',
                'tags'          => ['salon', 'warm', 'intimate', 'portrait'],
                'plan_required' => 'pro',
                'capacity_min'  => 5,
                'capacity_max'  => 30,
                'sort_order'    => 12,
                'is_featured'   => false,
                'version'       => '1.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'wood',
                    'lighting_preset'  => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    'wall_height'            => 3.0,
                    'wall_depth'             => 0.15,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x2b241a',
                    'ceiling_height'         => 3.0,
                    'background_color'       => '0x1d1712',
                    'fog_color'              => '0x1d1712',
                    'fog_near'               => 10,
                    'fog_far'                => 32,
                    'ambient_color'          => '0xffdcae',
                    'ambient_intensity'      => 0.26,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.16,
                    'tone_mapping_exposure'  => 0.6,
                    'frame_override'         => null,
                    // ── Iteration 8 declared identity (rollback = remove
                    // these keys — the venue reverts to a plain default
                    // room, live; no deploy, no flag).
                    'structure_pass'        => 'rooms',
                    'placement'             => [
                        'density'          => 'intimate',  // §6.3 — ~2.8 m salon-close rhythm
                        'pair_orientation' => true,        // §6.4 — portrait/landscape interleave
                    ],
                    'structure'              => [
            // ── Picture rail — one thin timber line fitted to each wall
            // (fit: 'wall' stretches it to the wall span, minus corner
            // pads). The salon-wall convention made physical.
            ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-back',  'primitive' => 'box', 'at' => ['from' => 'wall_back',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-left',  'primitive' => 'box', 'at' => ['from' => 'wall_left',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            // ── Bench — the domestic datum (§4.5 convention, centred),
            // colliding so walkers respect it.
            ['id' => 'bench-top',  'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
            ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [-0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
            ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
            // ── Rug — the one warm floor note. Flat: walkable, not an
            // obstacle (no collide), cheapest tier renders it.
            ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [0, 0.012, 1.4]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 1.8], 'material' => 'fabric_warm', 'tier_floor' => 'low'],
        ],
                ],
                'material_config' => [
                    'wall_color'             => '0xe6dcc6',
                    'wall_roughness'         => 0.92,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.35,
                    'floor_color'            => '0x6b5236',
                    'floor_roughness'        => 0.65,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.55,
                ],
                'decorations'       => [],  // rail + bench + rug are descriptors (StructureBuilder)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square'],
            ],
        ];
    }
}

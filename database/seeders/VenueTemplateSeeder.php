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
 *   6. crystal-cathedral   — Faceted crystal arcade: piers, pointed arches, oculus light
 *   7. nebula-drift        — Deep-field nebula band, stardrift current, ring of light
 *   8. the-salon           — Close-hung warmth, domestic scale (Iteration 8)
 *
 * STUDIO PLAN:
 *   9. luxury-penthouse    — Moody collector space, marble + gold
 *  10. cyber-gallery       — Dark futuristic space with neon accents
 *  11. sculpture-garden    — Curated landscape: terrain, walks, courts, groves
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
                    // s4 environment authority: the venue declares its sky.
                    'environment'            => 'studio',
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
                    'environment'    => 'none',  // s4: declared — no environment, ever
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
                    // s4: the venue declares its sky (was dramatic→night.hdr).
                    'environment'            => 'night',
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
            // 4. Dark Museum — Pro ("the night wing", v2.0.0 deepening)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Dark Museum',
                'slug'          => 'dark-museum',
                'description'   => 'A night-lit institution: charcoal galleries under a shadow-gap black ceiling, brass picture lights over every work, polished dark stone below. The architecture recedes; the artwork glows.',
                'category'      => 'museum',
                'tags'          => ['dramatic', 'premium', 'dark'],
                'plan_required' => 'pro',
                'capacity_min'  => 15,
                'capacity_max'  => 50,
                'sort_order'    => 4,
                'is_featured'   => false,
                'version'       => '2.0.0',
                'default_settings' => [
                    // "white" is the PBR set (painted plaster relief); the
                    // venue's texture_tint below makes the declared charcoal
                    // colour authoritative over it — the v1.0.0 row shipped
                    // the tint key but never the flag, so the walls rendered
                    // White-Cube white (the audit's headline material find).
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
                    'ceiling_color'          => '0x0a0a0a',
                    'ceiling_height'         => 5,
                    'background_color'       => '0x050505',
                    'fog_color'              => '0x050505',
                    'fog_near'               => 12,
                    'fog_far'                => 70,
                    'ambient_color'          => '0xffe8c8',
                    'structure_pass'         => 'museum',  // IT-DarkMuseum: night wing
                                                            // (shadow gap, baseboard, salon
                                                            // cabinets, picture lights)
                    'ambient_intensity'      => 3.2,
                    'spot_intensity'         => 1.9,
                    'fill_intensity'         => 0.5,
                    'tone_mapping_exposure'  => 0.8,
                    'frame_override'         => 'gold',
                    // Dark-venue artwork legibility (audit rule: no artwork
                    // sits in the dark): standing glow + pool raise.
                    'artwork_light_base'     => 0.32,
                    'artwork_light_pool_cap' => 14,
                    // s4 ENVIRONMENT AUTHORITY (the cloud-sheen root-cause
                    // fix): the venue now DECLARES its sky as 'night' — the
                    // museum's environment identity can no longer depend on
                    // which lighting preset a gallery's stale row happens to
                    // carry. night.hdr keeps a subtle reflection source for
                    // the brass picture lights and gold frames WITHOUT the
                    // daytime cloud deck rural_evening painted across the
                    // polished dark stone (the deployed sky/cloud-on-floor
                    // report). Env strength stays at the declared whisper.
                    'env_intensity'          => 0.14,
                    'environment'            => 'night',
                    // The shared hemisphere wash is now venue-declarable;
                    // the museum flattens it to keep its hierarchy.
                    'hemisphere_intensity'   => 0.04,
                    // Restraint, declared: bloom halos cheapen a dark hang;
                    // the vignette deepens the night focus instead. The blend
                    // mode is the audit's ROOT-CAUSE find: the stock Eskil
                    // shader blends edges toward a LIGHT-GREY constant
                    // (1 - darkness), which ADDS a grey glow to a dark scene
                    // — 'black' blends toward true black instead (a real
                    // optical vignette).
                    'post_fx'                => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_blend'    => 'black',
                        'vignette_darkness' => 0.5,
                        'vignette_offset'   => 1.15,
                    ],
                    // Curation (§6): a museum hangs fewer, spaced works with
                    // a composed arrival wall and mixed-orientation runs.
                    'placement'              => [
                        'density'          => 'generous',
                        'focal_wall'       => 'front',
                        'pair_orientation' => true,
                    ],
                ],
                'material_config' => [
                    // The tint flag IS the fix: without it the declared
                    // colours below never reach textured builds (the
                    // Infinite Void preview/product split, unfixed here).
                    'texture_tint'           => true,
                    'wall_color'             => '0x7a746c',
                    'wall_roughness'         => 0.92,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.5,
                    'floor_color'            => '0x3a3835',
                    'floor_roughness'        => 0.32,
                    'floor_metalness'        => 0.15,
                    'floor_normal_strength'  => 0.5,
                    'floor_tile_meters'      => 3.0,
                ],
                'decorations'       => [],  // the night wing is procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 5. Japanese Zen Gallery — Pro (v2.0.0 "The Quiet Procession")
            //
            // ZEN DEEPENING iteration (single-venue design brief: ONE strong
            // idea, not many effects). The v1.0.0 row was the failure mode
            // the brief names — "a normal gallery with Japanese
            // decorations": a pre-polish rig (exposure 0.55, spot 0.45, the
            // measured ~10× too dim profile) with shoji/tokonoma props at
            // ABSOLUTE coordinates that broke on every layout change.
            // v2 is a complete spatial concept:
            //
            //   ONE IDEA — the FRAMED BAY. Dark timber fins divide warm
            //   plaster walls into bays; each work sits centred in its own
            //   bay between generous expanses of blank wall (ma). A
            //   recessed plaster panel backs every bay, a timber header
            //   closes it, a paper clerestory band glows above the headers,
            //   and a low timber step (the engawa datum) runs the wall at
            //   the floor. Slim rafters carry the same rhythm overhead.
            //   The bay architecture derives from the SAME pure run plan
            //   the artwork placer consumes (structure_pass 'bays'), so
            //   every artwork — on every supported layout, at any count —
            //   lands centred in its own framed bay. No RNG anywhere.
            //
            //   MATERIAL HIERARCHY (4 voices, texture_tint authority): warm
            //   limewash plaster walls, pale cedar floor at tatami scale
            //   (tile 1.8 m), dark sumi-stained timber structure, glowing
            //   paper. Sumi-ink frame_override. Architectural warmth
            //   (ambient 0xfff2dd) is separated from the near-neutral
            //   artwork pool — artwork colour stays honest.
            //
            //   ENVIRONMENT DECISION: 'none' — a sealed interior. No HDRI
            //   download, no sky can ever leak in (declared absence, the
            //   Infinite Void authority pattern). env_intensity 0.
            //
            //   ROTUNDA DROPPED: the procession is linear; a circular zen
            //   would be a different venue. Legacy rotunda rows clamp to
            //   the venue default via layoutForGallery.
            //
            // Production rows are updated by the GUARDED migration
            // 2026_09_07_000002_zen_gallery_deepening — this seeder stays
            // the fresh-install baseline. (scripts/venue-qa/
            // zen-gallery-qa.mjs pins the pair.)
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Japanese Zen Gallery',
                'slug'          => 'zen-gallery',
                'description'   => 'A low, calm room of framed bays: dark timber fins divide warm plaster walls, each work centred in its own bay beneath a glowing paper band, over a pale cedar floor. Made for close, quiet looking.',
                'category'      => 'minimal',
                'tags'          => ['zen', 'natural', 'calm'],
                'plan_required' => 'pro',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 5,
                'is_featured'   => false,
                'version'       => '2.0.0',
                'default_settings' => [
                    'wall_texture'    => 'plaster',
                    'floor_material'  => 'wood',
                    'lighting_preset'  => 'bright',
                    // The venue declares sumi-ink frames via frame_override;
                    // the picker starts coherent with it.
                    'frame_style'     => 'black',
                    'room_layout'     => 'square',
                ],
                'visual_config' => [
                    // A low, calm room — bays keep the proportion human.
                    'wall_height'            => 3.6,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0xe9e2d0',  // warm paper plane
                    'ceiling_height'         => 3.6,
                    // The room dissolves into warm light at distance (ma) —
                    // never into soot, never into colour.
                    'background_color'       => '0xeee7d8',
                    'fog_color'              => '0xeee7d8',
                    'fog_near'               => 18,
                    'fog_far'                => 60,
                    // Warm architecture, honest artwork: the ambient carries
                    // the limewash; the artwork pool light (0xfff5e6,
                    // renderer-owned) stays near-neutral above it.
                    'ambient_color'          => '0xfff2dd',
                    'ambient_intensity'      => 0.5,
                    'spot_intensity'         => 2.2,
                    'fill_intensity'         => 1.3,
                    'tone_mapping_exposure'  => 0.95,
                    'frame_override'         => 'black',
                    // Calm standing glow; a typical hang lights at once.
                    'artwork_light_base'     => 0.25,
                    'artwork_light_pool_cap' => 10,
                    'hemisphere_intensity'   => 0.1,
                    // s4 ENVIRONMENT AUTHORITY — declared absence: a sealed
                    // interior, no sky, no reflections but the lamps'.
                    'environment'            => 'none',
                    'env_intensity'          => 0,
                    // ── v2 declared identity (rollback = remove these keys —
                    // the venue reverts to a plain default room, live) ────
                    'structure_pass'        => 'bays',
                    'bays'                   => [
                        'fin_width'         => 0.16,
                        'fin_depth'         => 0.14,
                        'fin_top'           => 3.12,
                        'header_height'     => 0.20,
                        'recess_lift'       => 0.012,
                        'step_height'       => 0.08,
                        'step_depth'        => 0.36,
                        'clerestory_gap'    => 0.05,
                        'clerestory_height' => 0.24,
                    ],
                    // Curation: the procession rhythm — generous bays,
                    // composed orientations, one quiet arrival hero.
                    'placement'              => [
                        'density'          => 'generous',
                        'focal_wall'       => 'front',
                        'pair_orientation' => true,
                    ],
                    // Post-processing identity: restraint. Calm is the brand.
                    'post_fx'                => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_darkness' => 0.3,
                        'vignette_offset'   => 1.1,
                    ],
                ],
                'material_config' => [
                    // Declared colours are authoritative over the PBR sets
                    // (texture_tint IS the fix — see the museum audit).
                    'texture_tint'           => true,
                    'wall_color'             => '0xe6dfcf',
                    'wall_roughness'         => 0.95,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.35,
                    'floor_color'            => '0xa98d64',
                    'floor_roughness'        => 0.62,
                    'floor_metalness'        => 0.0,
                    'floor_normal_strength'  => 0.5,
                    // Tatami rhythm — the floor reads at mat scale.
                    'floor_tile_meters'      => 1.8,
                ],
                'decorations'       => [],  // the bay architecture is procedural
                                            // (VenueDecorator 'bays' pass) —
                                            // no props, no clichés
                'lighting_fixtures' => [],
                // The procession is linear; rotunda is gone (legacy rotunda
                // rows clamp to the square default via layoutForGallery).
                'supported_layouts' => ['square', 'corridor', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 6. Crystal Cathedral — Pro ("The Luminous Arcade", 2026-09-07)
            //
            // Venue-deepening iteration: the seeded body WAS twelve thin
            // smooth-shaded glass tubes + four pastel-rainbow point lights
            // in a blue void — crystal as decoration, no cathedral. The
            // architecture is now the identity: an adaptive colonnade of
            // faceted crystal piers carrying POINTED ARCHES around a ring of
            // framed stone art bays, a clerestory crystal band under a
            // luminous seam, and a radial rib vault converging on a luminous
            // oculus above the crossing. Artworks hover before the stone
            // bay wall; the dark slate floor carries a declared planar
            // reflection on high tier. Ice-white light family — the blue
            // lives in the atmosphere, never in the material (colour
            // restraint). The guarded migration
            // 2026_09_07_000001_crystal_cathedral_architecture updates
            // production rows; this seeder is the fresh-install baseline
            // (byte-equal to the migration's final state, pinned by test).
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Crystal Cathedral',
                'slug'          => 'crystal-cathedral',
                'description'   => 'A colonnade of faceted crystal piers carries pointed arches around a hall of polished dark stone; light falls from a vaulted oculus and reflects across the floor while artworks float before framed bays of stone.',
                'category'      => 'abstract',
                'tags'          => ['crystal', 'colonnade', 'luminous', 'ethereal'],
                'plan_required' => 'pro',
                'capacity_min'  => 5,
                'capacity_max'  => 40,
                'sort_order'    => 6,
                'is_featured'   => true,
                'version'       => '2.1.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset'  => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
                'visual_config' => [
                    'wall_height'            => 13,      // the arcade's order height
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'none',
                    'ceiling_height'         => 0,
                    'background_color'       => '0x070b14',
                    'fog_color'              => '0x070b14',
                    'fog_near'               => 18,
                    'fog_far'                => 62,      // the arcade must survive 40-work scale
                    'ambient_color'          => '0xbfd4ec',
                    'ambient_intensity'      => 0.34,
                    'spot_intensity'         => 1.15,    // pool target ≈ 4.0 — artworks read
                    'fill_intensity'         => 0.22,
                    'tone_mapping_exposure'  => 0.85,
                    'frame_override'         => 'silver',
                    // ── Declared identity (the luminous arcade body) ────
                    'placement_mode'  => 'float',  // §10.5
                    'glass_material'  => 'transmission',  // §11.3 row 2 — tier-resolved
                    'colonnade_tint'  => '0xe6f0fb',  // ice-white crystal; blue stays in the sky
                    'structure_pass'  => 'phenomena',  // per-venue rollback switch
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    'void_arcade'     => true,   // NEW composed body (piers + arches +
                                                 // art bays + clerestory + rib vault +
                                                 // oculus). Rollback chain:
                                                 // void_colonnade → IT2 glass-tube ring;
                                                 // void_shards → Iteration 0 shard ring.
                    // ── Atmosphere: a DECLARED sky (was preset accident) ─
                    'environment'            => 'studio',  // neutral glass definition
                    'env_intensity'          => 0.22,
                    'hemisphere_intensity'   => 0.30,      // sky-above gradient cue — v2.1.0:
                                                           // lifts the art-bay wall band + pier
                                                           // bases (deploy review: the "black
                                                           // mid-band" the veil used to hide)
                    'void_depth_gradient'    => true,      // zenith depth above the vault
                    // ── Artwork legibility (void family standing glow) ──
                    'artwork_light_base'     => 0.38,
                    'artwork_light_pool_cap' => 12,
                    // ── Curation opt-in: depth-layered hang past 12 works ─
                    'placement'       => ['depth_bands' => 2],
                    // ── Restrained luminosity (was undeclared stock bloom) ─
                    'post_fx'         => [
                        'bloom'             => true,
                        'bloom_strength'    => 0.42,
                        'bloom_threshold'   => 0.82,   // catches oculus + seam only
                        'bloom_radius'      => 0.35,
                        'vignette'          => true,
                        'vignette_darkness' => 0.62,
                        'vignette_offset'   => 1.15,
                        'vignette_blend'    => 'black', // v2.1.0 deploy review — the
                                                     // legacy GREY target (1−0.62 = 0.38)
                                                     // LIFTED the frame edges of a
                                                     // blue-black venue ~+0.2 luminance
                                                     // (the production "haze"). Dark
                                                     // Museum audit precedent.
                    ],
                    // The copy promises the reflection — declare it
                    // (Reflector high tier / designed gloss mobile+low-end).
                    'floor_reflection'  => 'planar',
                ],
                'material_config' => [
                    'wall_color'             => '0x131a26',  // art-bay stone (deep slate-blue)
                    'wall_roughness'         => 0.3,
                    'wall_metalness'         => 0.06,
                    'wall_normal_strength'   => 0.25,
                    'floor_color'            => '0x1a2230',  // polished dark slate
                    'floor_roughness'        => 0.22,
                    'floor_metalness'        => 0.2,
                    'floor_normal_strength'  => 0.25,
                    'floor_tile_meters'      => 2.5,         // slab scale, not tile scale
                    // Declared colours are authoritative over the marble
                    // texture (Materials.js tint path) — the cathedral's
                    // stone is the declared slate, not stock cream marble.
                    'texture_tint'           => true,
                ],
                'decorations'       => [],  // the arcade architecture is procedural
                                            // (VenueDecorator 'void_arcade' body) —
                                            // no props, no clichés
                'lighting_fixtures' => [],  // the oculus key light is part of the
                                            // arcade body (one SpotLight, budget kept)
                'supported_layouts' => ['rotunda'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 7. Nebula Drift — Pro ("The Deep Field", 2026-09-08 audit
            //    pass; v2.1.0 arch pass + v2.2.0 identity pass after the
            //    deploy reviews)
            //
            // The v1.0.0 row answered the name test with "a dark room with
            // purple fog and stars": a double purple centre light (seeded
            // fixture + the body's own — stacked), all-violet stars, a flat
            // additive particle box through the artwork zone, an ambient
            // that tinted every artwork canvas purple, a floor whose
            // declared colour never reached the marble texture, the legacy
            // grey-blend vignette, and a night.hdr download that was being
            // silenced anyway. Production rows are updated by the GUARDED
            // migrations 2026_09_08_000002 (Deep Field), _000003 (arch/
            // deploy-review) and _000004 (identity: crown luminosity
            // profile, near veil, ring demoted to a thread, monolith
            // visibility, artwork island glow, elevation-step hang) — this
            // seeder stays the fresh-install baseline and must stay
            // byte-consistent with those migrations' outcome.
            //
            // The declared identity (v2.2.0 "the nebula owns the sky"): ONE
            // immense galactic arch with a luminous core wheeling overhead
            // (steeply tilted band, crown-weighted feature masses, haze
            // backbone, near veil drifting past overhead), a neutral
            // two-strata starfield, colossal dark silhouettes standing in
            // the band glow, a stardrift current streaming through the
            // exhibition, one meridian thread of travelling light overhead
            // as the arrival anchor, and a pool of cool light under every
            // floating artwork. The hang itself composes vertically
            // (placement.elevation_step) — a suspended constellation, not a
            // flat ring. The floor dissolves to black at the disc's rim
            // (floor_fade_span 1.16 — the void wraps beneath; no stage
            // edge) and the background is pure black so sky and ground meet
            // without a seam. Colour hierarchy: black void → violet/blue
            // nebular masses → ONE rare rose accent. The only warm light in
            // the venue is the artwork pool lighting (§12 artwork honesty).
            // The legacy starfield body stays reachable via config revert
            // (void_deepfield → void_starfield).
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Nebula Drift',
                'slug'          => 'nebula-drift',
                'description'   => 'A deep-field nebula owns the sky — one immense galactic arch with a luminous core wheeling overhead, colossal silhouettes at its edges, a stardrift current, and a meridian thread of travelling light. Artworks hang as a suspended constellation over pools of light, the floor dissolving into the void.',
                'category'      => 'abstract',
                'tags'          => ['cosmic', 'nebula', 'deep-field', 'ethereal'],
                'plan_required' => 'pro',
                'capacity_min'  => 5,
                'capacity_max'  => 50,
                'sort_order'    => 7,
                'is_featured'   => true,
                'version'       => '2.2.0',
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
                    'background_color'       => '0x000000',  // the dissolve meets the dome's black horizon exactly (no stage line)
                    'fog_color'              => '0x000000',
                    'fog_near'               => 12,
                    'fog_far'                => 70,
                    'ambient_color'          => '0x7a86b8',  // moon-slate — NEVER purple (artwork honesty)
                    'ambient_intensity'      => 0.62,  // the spawn view is 12–20 m out: the wash must carry unlit canvases at that distance
                    'hemisphere_intensity'   => 0.35,  // vertical fill — unlit far canvases stay legible
                    'spot_intensity'         => 1.35,  // pool target ≈ 4.7 (void family)
                    'fill_intensity'         => 0.15,
                    'tone_mapping_exposure'  => 0.85,  // was 0.6 murk
                    'frame_override'         => null,
                    // ── Iteration 2 "Phenomena" declared identity ──────
                    'placement_mode'  => 'float',  // §4.7 — "drift", not "stand"
                    'env_intensity'  => 0,  // environment 'none' skips the HDRI download entirely
                    'environment'    => 'none',  // the sky is procedural — no HDRI, ever
                    'structure_pass'  => 'phenomena',  // per-venue rollback switch
                    'open_air'        => true,
                    'layout_shape'    => 'circular',
                    // ── Deep Field identity (2026-09-08 audit pass) ────
                    'void_deepfield'   => true,  // layered band sky + current + ring (replaces void_starfield)
                    'void_depth_gradient' => true,  // shared zenith depth cue
                    'floor_edge_fade'  => true,  // the ground dissolves into the void
                    'floor_fade_span'  => 1.16,  // the phantom plane ENDS at the dissolve — the void wraps beneath (no stage edge)
                    'artwork_light_base'     => 0.72,  // v2.2.0 K8: the standing glow — works read as lit islands at spawn distance
                    'artwork_light_pool_cap' => 12,  // a 12-piece hang lit at once
                    // Colour hierarchy, venue-owned (s6): dominant atmosphere
                    // → secondary masses → ONE rare warm accent.
                    'nebula' => [
                        'dominant'  => '0x5a4ae0',
                        'secondary' => '0x2e6ac8',
                        'accent'    => '0xd85a9e',
                    ],
                    // Restrained post-fx (the legacy grey veil never ships again).
                    'post_fx' => [
                        'bloom'             => true,
                        'bloom_strength'    => 0.35,
                        'bloom_threshold'   => 0.8,
                        'bloom_radius'      => 0.35,
                        'vignette'          => true,
                        'vignette_darkness' => 0.55,
                        'vignette_offset'   => 1.3,
                        'vignette_blend'    => 'black',
                    ],
                    // Depth-band curation + light pools under floating works
                    // (the post-placement hook reads placement.light_pools).
                    // v2.2.0 K9: elevation_step lifts each inner band (m) —
                    // the hang composes vertically (suspended constellation).
                    'placement' => [
                        'depth_bands'    => 2,
                        'light_pools'    => true,
                        'elevation_step' => 0.7,
                    ],
                ],
                'material_config' => [
                    'wall_color'             => '0x080015',
                    'wall_roughness'         => 0.4,
                    'wall_metalness'         => 0.2,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => '0x0b0724',  // deep indigo stone
                    'floor_roughness'        => 0.32,
                    'floor_metalness'        => 0.15,  // metal with no environment renders dead
                    'floor_normal_strength'  => 0.3,
                    // Declared colours are authoritative over the marble
                    // texture (Materials.js tint path) — the v1.0.0 floor
                    // shipped stock cream marble under a purple sky.
                    'texture_tint'           => true,
                ],
                'decorations'       => [],  // the Deep Field is procedural (VenueDecorator 'void_deepfield' body)
                'lighting_fixtures' => [
                    // ONE declared cold key light raking from the accent side
                    // of the band. (The v1.0.0 purple centre point was DOUBLED
                    // by the old body adding an identical light at the same
                    // position — the rig is one light, declared here.)
                    [
                        'id'          => 'nebula-key',
                        'type'        => 'directional',
                        'position'    => [30, 45, -18],
                        'color'       => '0x9ab0e0',
                        'intensity'   => 0.45,
                        'cast_shadow' => false,
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
                // Iteration "The Double Volume" (v3.0.0; guarded migration
                // 2026_09_09_000007 mirrors this). The architectural concept:
                // ONE seam line splits the floor into two conditions — a
                // low, coved gallery procession (wing A band, 3.55 m) and a
                // double-height living volume (6.3 m) glazed to the dusk
                // city on TWO faces (wing B end + the wing B north run,
                // 'glazing_walls'). The lit seam continues through wing B's
                // south wall; the stone fireplace pier stands inside the
                // north glass run and carries the statement work above the
                // fire; the terrace wraps the glass corner.
                'description'   => 'A private collector\'s floor in two volumes — a low, coved gallery procession that lifts at a lit seam into a double-height living room glazed to the dusk city on two faces, the largest work living above the stone fireplace, the terrace wrapping the glass corner.',
                'category'      => 'luxury',
                'tags'          => ['luxury', 'collector', 'private'],
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 8,
                'is_featured'   => true,
                'version'       => '3.0.0',
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset'  => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'l-shape',
                ],
                'visual_config' => [
                    // The NOMINAL height is the living volume; the gallery
                    // band is declared separately (wing_heights) — the
                    // venue's vertical identity is the STEP between them.
                    'wall_height'            => 6.3,
                    'wall_depth'             => 0.3,
                    'ceiling_type'           => 'flat',
                    'ceiling_color'          => '0x5c4c3a',  // warm lit plaster
                    'ceiling_height'         => 6.3,
                    // ── THE DOUBLE VOLUME: the declared vertical split.
                    // Gallery band 3.55 m (compression) → living volume
                    // 6.3 m (release). RoomBuilder splits the west wall at
                    // jZ and builds per-zone ceilings; StructureBuilder's
                    // anchors resolve the same numbers.
                    'wing_heights'           => ['wing_a' => 3.55, 'wing_b' => 6.3],
                    'background_color'       => '0x0b0f1a',
                    'fog_color'              => '0x191c26',  // dusk haze
                    'fog_near'               => 26,
                    'fog_far'                => 160,
                    'ambient_color'          => '0xe9dfcd',
                    'ambient_intensity'      => 0.42,
                    'spot_intensity'         => 0.5,
                    'fill_intensity'         => 0.42,
                    'tone_mapping_exposure'  => 0.92,
                    // Bronze — the trim line's own metal; gold read as
                    // decoration, bronze reads as the room's hardware.
                    'frame_override'         => 'bronze',
                    // ── The venue DECLARES its sky: a city dusk seen
                    // through the glazing — no HDRI download, no preset
                    // fallback (P4).
                    'environment'            => 'none',
                    'env_intensity'          => 0,
                    'hemisphere_intensity'   => 0.3,
                    // ── Artwork legibility floor: the BASE sits above half
                    // the proximity target so every canvas reads along the
                    // whole procession.
                    'artwork_light_base'     => 0.5,
                    'artwork_light_pool_cap' => 12,
                    // ── Declared post-processing: restrained bloom + the
                    // BLACK-blend vignette (P3 — the stock grey veil never
                    // ships again).
                    'post_fx'                => [
                        'bloom'             => true,
                        'bloom_strength'    => 0.32,
                        'bloom_threshold'   => 0.85,
                        'bloom_radius'      => 0.35,
                        'vignette'          => true,
                        'vignette_darkness' => 0.5,
                        'vignette_offset'   => 1.12,
                        'vignette_blend'    => 'black',
                    ],
                    // ── Declared identity: TWO glazed faces (the glass
                    // corner) + "The Double Volume" descriptors.
                    // 'glazing_wall' stays true so square-layout galleries
                    // keep the v1 single-face identity.
                    'glazing_wall'          => true,
                    'glazing_walls'         => ['wing_b_end', 'wing_b_north'],
                    'structure_pass'        => 'rooms',
                    'structure'              => [
            // ── THE SEAM (junction anchors): where 3.55 m becomes 6.3 m.
            // The exposed gallery roofline gets a plaster fascia; a lit slot
            // runs the FULL plan width at the step height — along the
            // gallery ceiling edge AND wing B's south wall — the seam made
            // visible from anywhere on the floor.
            ['id' => 'step-fascia', 'primitive' => 'box', 'at' => ['from' => 'junction', 'offset' => [0, 3.375, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.3, 'size' => [1, 0.35, 0.3], 'material' => 'plaster_warm'],
            ['id' => 'step-slot', 'primitive' => 'emissive-strip', 'at' => ['from' => 'junction_outside', 'offset' => [0, 3.47, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
            ['id' => 'seam-slot-inner', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'offset' => [0, 3.47, -0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
            // ── The axis sculpture: one bronze knot on basalt, exactly on
            // the spawn sightline at the seam — the procession's full stop.
            ['id' => 'plinth', 'primitive' => 'cylinder', 'at' => ['from' => 'junction', 'offset' => [0, 0.55, 1.5]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
            ['id' => 'sculpture-knot', 'primitive' => 'torus', 'at' => ['from' => 'junction', 'offset' => [0, 1.42, 1.5]], 'turn' => 'in', 'size' => [0.34, 0.1, 0.34], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
            // ── THE FIREPLACE PIER: the solid stone moment inside the north
            // glass run. Full volume height; hangable above the mantel (the
            // statement work lives here — bay hang, centre 3.25 m).
            ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 3.15, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 6.3, 0.2], 'material' => 'basalt', 'hangable' => ['y' => 3.25]],
            ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
            ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
            ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
            // ── THE ART WALL: full-height walnut panel on the volume's west
            // face (wall_left_high) — architectural art wall holding the
            // second statement work (bay hang, centre 2.6 m).
            ['id' => 'art-wall-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_left_high', 'offset' => [0, 2.6, 0.03]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.8, 'size' => [1, 5.2, 0.06], 'material' => 'walnut', 'hangable' => ['y' => 2.6]],
            // ── EAST GLASS (wing B end): tier-resolved cheap-class glass +
            // steel mullions, full volume height.
            ['id' => 'glazing-glass',  'primitive' => 'plane',          'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
            ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            // ── NORTH GLASS (wing B run — the second face, v3): the long
            // panorama; the fireplace pier interrupts it with stone.
            ['id' => 'glazing-glass-north',  'primitive' => 'plane',          'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
            ['id' => 'glazing-mullions-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            // ── TERRACE: warm decks + rails wrapping BOTH glazed faces
            // (the glass corner).
            ['id' => 'terrace-deck-east',  'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
            ['id' => 'terrace-deck-north', 'primitive' => 'box', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
            ['id' => 'rail-bar-east',  'primitive' => 'box',           'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts-east', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            ['id' => 'rail-bar-north',  'primitive' => 'box',           'at' => ['from' => 'glazing_north', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            // ── Dusk city, TWO faces: three grounded depth layers per face
            // (seeded §13.6) + afterglow bands BETWEEN and ABOVE the
            // silhouettes (the v2.1.0 facing-planes lesson kept).
            ['id' => 'skyline-near-east', 'primitive' => 'instance-grid', 'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [34, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-mid-east',  'primitive' => 'instance-grid', 'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [36, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-far-east',  'primitive' => 'instance-grid', 'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-far-b', 'area' => ['from' => 'glazing_outside', 'size' => [50, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'horizon-glow-east', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
            ['id' => 'sky-mid-east',      'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
            ['id' => 'sky-deep-east',     'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [150, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
            ['id' => 'skyline-near-north', 'primitive' => 'instance-grid', 'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-n1', 'area' => ['from' => 'glazing_north_outside', 'size' => [44, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-mid-north',  'primitive' => 'instance-grid', 'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-n2', 'area' => ['from' => 'glazing_north_outside', 'size' => [46, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-far-north',  'primitive' => 'instance-grid', 'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-n3', 'area' => ['from' => 'glazing_north_outside', 'size' => [56, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'horizon-glow-north', 'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [150, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
            ['id' => 'sky-mid-north',      'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [160, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
            ['id' => 'sky-deep-north',     'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [180, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
            // ── Gallery band: warm cove reveals at the 3.55 m ceiling (the
            // low wing's light comes from the coves + the seam slot).
            ['id' => 'cove-left',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            ['id' => 'cove-right',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            ['id' => 'cove-front',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            // ── Bronze base trim: the quiet luxury line (gallery band +
            // the solid seam wall in the volume; never across glass).
            ['id' => 'base-left',   'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-right',  'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-front',  'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-inner',  'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            // ── Gallery bench: under the west-face hang, never blocking
            // the walk.
            ['id' => 'bench-top',      'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
            ['id' => 'bench-base',     'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
            // ── Lounge at the glass corner (few, strong pieces — the sofa
            // faces the room, the city wraps it; pendant above).
            ['id' => 'lounge-rug',     'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0.5, 0.012, 2.0]],  'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.4, 2.5], 'material' => 'fabric_dark'],
            ['id' => 'sofa-base',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.24, 1.75]],  'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
            ['id' => 'sofa-back',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.72, 2.15]],  'turn' => 'in', 'size' => [2.3, 0.5, 0.24],  'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-l',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-0.76, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-r',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [1.76, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'table-top',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.34, 0.9]],   'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
            ['id' => 'table-pedestal', 'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.15, 0.9]],   'turn' => 'in', 'size' => [0.5, 0.3, 0.35],  'material' => 'dark_trim', 'collide' => true],
            ['id' => 'chair-seat',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.45, 0.3, 2.9]], 'turn' => 'in', 'size' => [0.85, 0.44, 0.85], 'material' => 'fabric_dark', 'collide' => true],
            ['id' => 'chair-back',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.45, 0.63, 3.35]], 'turn' => 'in', 'size' => [0.12, 0.55, 0.85], 'material' => 'fabric_dark'],
            ['id' => 'lamp-pole',      'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.55, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
            ['id' => 'lamp-shade',     'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.55, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
            ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0.5, 4.6, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
            // ── Stone slab joints: the large-format floor scale cue
            // (procedural grout grid — zero new assets, §14).
            ['id' => 'floor-joints',   'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.02], 'material' => ['color' => '0x6b5f4c', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
        ],
                ],
                'material_config' => [
                    'wall_color'             => '0xe9e2d4',
                    'wall_roughness'         => 0.9,
                    'wall_metalness'         => 0.0,
                    'wall_normal_strength'   => 0.3,
                    'floor_color'            => '0x9b8d78',
                    'floor_roughness'         => 0.62,
                    'floor_metalness'         => 0.03,
                    'floor_normal_strength'  => 0.35,
                    // The declared colours ARE the identity (textured builds
                    // tint by them); slab scale for the honed stone floor.
                    'texture_tint'           => true,
                    'floor_tile_meters'      => 2.4,
                ],
                'decorations'       => [],
                // ── The residence's warm sources: the fire line, the hearth
                // wash up the tall pier, the SEAM wash (the lit step), the
                // gallery cove wash, and the lounge wash (5 dynamic lights
                // — the pooled-light budget is untouched, PERF-B18).
                'lighting_fixtures' => [
                    ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
                    ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 5.0, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'step-wash', 'type' => 'point', 'anchor' => ['from' => 'junction', 'offset' => [0, 4.65, 1.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 14, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'gallery-cove-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 2.8, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0.5, 4.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
                ],
                'supported_layouts' => ['square', 'l-shape'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 9. Cyber Gallery — Studio (v2.0.0 "Signal Room")
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Cyber Gallery',
                'slug'          => 'cyber-gallery',
                // v2.0.0 "Signal Room": the venue's signature interaction is
                // now DECLARED and RENDERED — the artworks are living digital
                // media that react to visitor movement (stillness = clarity,
                // movement = controlled digital instability, stop = recovery).
                // Copy promises only what renders (honesty-pass contract).
                'description'   => 'A signal room for digital natives: dark anodized walls, a floor traced in light, neon ringing every edge — and artworks that behave like living media. Stand still and they hold still. Move, and they react to you.',
                'category'      => 'futuristic',
                'tags'          => ['cyberpunk', 'neon', 'digital', 'web3'],
                'plan_required' => 'studio',
                'capacity_min'  => 20,
                'capacity_max'  => 100,
                'sort_order'    => 9,
                'is_featured'   => true,
                'version'       => '2.0.0',
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
                    'fog_near'               => 10,
                    'fog_far'                => 26,
                    'ambient_color'          => '0x3060ff',
                    'ambient_intensity'      => 0.42,
                    'spot_intensity'         => 1.6,
                    'fill_intensity'         => 0.4,
                    'tone_mapping_exposure'  => 0.7,
                    'frame_override'         => 'black',     // device-bezel frame — the
                                                             // luminous boundary supplies the colour
                    // ── v2.0.0 declared environment absence: a controlled
                    // signal room refuses a sky. No 10 MB HDRI download, no
                    // preset night reflections recomposing the darkness.
                    'environment'            => 'none',
                    'env_intensity'          => 0,
                    'hemisphere_intensity'   => 0.05,
                    // The exhibition rule in a dark venue: no artwork sits in
                    // the dark (same contract the museum pass enforces).
                    'artwork_light_base'     => 0.28,
                    'artwork_light_pool_cap' => 12,
                    // ── Bloom is Cyber identity (the neon + luminous bezels
                    // read as light sources), declared explicitly with the
                    // black-blend vignette for the dark scene.
                    'post_fx'                => [
                        'bloom'             => true,
                        'bloom_strength'    => 0.55,
                        'bloom_threshold'   => 0.8,
                        'bloom_radius'      => 0.4,
                        'vignette'          => true,
                        'vignette_blend'    => 'black',
                        'vignette_darkness' => 0.55,
                        'vignette_offset'   => 1.1,
                    ],
                    // ── v2.0.0 SIGNATURE: movement-reactive artwork media ──
                    // The artworks are living digital media. A visitor standing
                    // still sees clean, gallery-legible canvases; movement
                    // drives a controlled digital instability (slice tearing,
                    // subtle channel separation, scanline interference); stop,
                    // and the effect decays smoothly back to clarity.
                    // Tuning only — tier degradation is designed in code
                    // (ArtworkReactive.resolveReactiveMode).
                    'artwork_reactive'       => [
                        'enabled'       => true,
                        'dead_zone'     => 0.18,  // m/s — numeric noise never triggers
                        'ref_speed'     => 3.0,   // m/s — walking pace saturates the reaction
                        'attack'        => 0.18,  // s — responsive rise (a few frames)
                        'release'       => 1.1,   // s — remains briefly, then settles
                        'max_intensity' => 1.0,
                        'bezel_color'   => '0x00e5ff',
                    ],
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
                    // v2.0.0: texture_tint is THE parity fix — the declared
                    // dark anodized tint now reaches textured builds (before,
                    // the concrete PBR set silently re-tinted every desktop
                    // wall 0xffffff while low-end rendered the declared dark:
                    // the two tiers disagreed about the venue's own walls).
                    'texture_tint'           => true,
                    'wall_color'             => '0x0a0a14',
                    'wall_roughness'         => 0.6,
                    'wall_metalness'         => 0.3,
                    'wall_normal_strength'   => 0.5,
                    'floor_color'            => '0x0b0d14',
                    'floor_roughness'        => 0.35,
                    'floor_metalness'        => 0.55,
                    'floor_normal_strength'  => 0.5,
                    'floor_tile_meters'      => 2.0,
                ],
                'decorations'       => [],  // neon strips are procedural (VenueDecorator)
                'lighting_fixtures' => [],
                'supported_layouts' => ['square', 'corridor'],
            ],

            // ─────────────────────────────────────────────────────────────
            // 10. Outdoor Sculpture Garden — Studio (v3.0.0 "The Curated Walk")
            // ─────────────────────────────────────────────────────────────
            [
                'name'          => 'Outdoor Sculpture Garden',
                'slug'          => 'sculpture-garden',
                'description'   => 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.',
                'category'      => 'outdoor',
                'tags'          => ['outdoor', 'garden', 'sculpture', 'open-air'],
                'plan_required' => 'studio',
                'capacity_min'  => 5,
                'capacity_max'  => 30,
                'sort_order'    => 10,
                'is_featured'   => false,
                'version'       => '3.0.0',
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
                    'background_color'       => '0xd6e0e2',  // horizon haze — the world tone behind the dome
                    'fog_color'              => '0xd6e0e2',  // aerial perspective (structure re-ranges per radius)
                    'fog_near'               => 18,
                    'fog_far'                => 45,
                    'ambient_color'          => '0xe8f1f8',
                    // IT6 consolidation keys — replace the OPEN_AIR / CIRCULAR
                    // slug sets and select the bespoke garden interpreter:
                    'open_air'               => true,
                    'layout_shape'           => 'circular',
                    'structure_pass'         => 'garden',
                    'ambient_intensity'      => 0.16,         // the hemisphere rig carries the daylight
                    'spot_intensity'         => 0.3,
                    'fill_intensity'         => 0.2,
                    'tone_mapping_exposure'  => 0.9,
                    'frame_override'         => null,
                    // Iteration 3 (§4.10): the garden is the only venue whose sky
                    // establishes a sun — high-tier-only sun shadows, config-gated
                    // (rollback: remove key).
                    'sun_shadows'           => true,
                    // ── v3.0.0 "The Curated Walk" declared identity ─────
                    'environment'            => 'none',  // no HDRI download; the sky is the dome below
                    'env_intensity'          => 0.22,    // IBL strength (PMREM rendered from the dome)
                    'hemisphere_intensity'   => 0.4,     // sky-over-grass daylight bounce
                    'hemisphere_sky_color'   => '0xbfd9ee',
                    'hemisphere_ground_color'   => '0x51663c',
                    'ceiling_fill_light'     => false,   // no glowing orb in an open sky
                    'field_radius_bonus'     => 2.2,     // a landscape needs ground per artwork
                    'field_radius_min'       => 12.5,    // …and a 5-piece show still composes as a garden
                    'placement_mode'         => 'garden',// curated courts, approaches, hierarchy (GardenLayout.js)
                    'artwork_light_base'     => 0.22,    // gentle standing glow — no dead canvases in daylight
                    'garden'                 => [
                        'sky_environment' => true,           // PMREM from the dome (rollback: false)
                    ],
                    // Daylight needs no glow: bloom off (it milked the sky),
                    // a gentle corner vignette keeps the frame composed.
                    'post_fx'                => [
                        'bloom'             => false,
                        'vignette'          => true,
                        'vignette_darkness' => 0.42,
                        'vignette_offset'   => 1.15,
                        'vignette_blend'    => 'black',
                    ],
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
                'decorations'       => [],  // terrain, walks, courts, vegetation are plan-built (VenueDecorator)
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
            ['id' => 'bench-top',  'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]], 'turn' => 'in', 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 3 "Rooms" (roadmap P1.3): the Room-family identity pass.
 *
 * WHAT IT SHIPS (data side)
 * -------------------------
 * StructureBuilder (resources/js/gallery/StructureBuilder.js) is the new
 * generic interpreter for `visual_config.structure` — a deliberately small
 * descriptor vocabulary (≤10 primitives, §10.3) rendered with ZERO venue
 * knowledge in JS. This migration gives the vocabulary its first consumers:
 *
 *   zen-gallery      shoji screens (wood frames + translucent paper),
 *                    tokonoma alcove (platform, back panel, jambs, lintel,
 *                    scroll, stone), low bench — the Pro venue stops being
 *                    "a short warm box" (§4.5 REDESIGN).
 *   luxury-penthouse glazing wall (visual_config.glazing_wall — RoomBuilder
 *                    replaces the wing-B end wall), mullions, terrace deck +
 *                    rail, seeded night-skyline towers, lounge group (rug,
 *                    sofa, low table) + warm pendant — the Studio flagship
 *                    gets its residential program (§4.8 REDESIGN).
 *   cyber-gallery    full perimeter neon (all four edges, ceiling level),
 *                    floor light grid + rails — the identity survives with
 *                    bloom OFF (emissive reads intrinsically, §4.9 REDESIGN).
 *   white-cube       structure_pass only — the respect pass (base reveal,
 *                    crown line, visible ceiling fixtures) is bespoke code
 *                    gated on this key (§4.1 PRESERVE + REFINE).
 *   sculpture-garden sun_shadows — the only venue where the sky establishes
 *                    a sun; high-tier-only sun shadows, config-gated (§4.10).
 *   dark-museum      default wall texture brick → white (painted museum wall
 *                    under the dark tint; brick reads loft, §4.4 REFINE).
 *                    Existing galleries keep their stored texture — this is
 *                    the venue DEFAULT only.
 *
 * Copy re-tightened (the P0.1 loop: words → render → words) for the three
 * descriptor venues — each rewrite GUARDED by exact-match on the Iteration 0
 * text, so a super-admin's customized description is never touched.
 *
 * SAFETY (production data protection) — same pattern as Iteration 2:
 * visual_config / material_config keys merge with array UNION (admin-set keys
 * are NEVER overwritten; only absent keys are added). Museum's wall_texture
 * is guarded by exact match. Portable PHP read-modify-write (no MySQL-only
 * JSON functions) — runs identically on MySQL/MariaDB and sqlite tests.
 * Idempotent: re-running adds nothing (keys present) and rewrites nothing
 * (guards miss).
 *
 * ROLLBACK: down() removes exactly the keys up() added (only while still
 * equal to what up() wrote) and restores the Iteration 0 descriptions.
 * Per-venue, no-deploy rollback at RUNTIME is simpler: remove structure_pass
 * (or the structure array) from one venue's JSON and that venue reverts
 * live — the interpreter only renders on the declared keys.
 */
return new class extends Migration
{
    private const OLD_DESCRIPTIONS = [
        'zen-gallery'      => 'Minimal architecture with natural wood finishes and calm, warm light. A quiet, focused atmosphere.',
        'luxury-penthouse' => 'A moody, intimate collector space. Dark walls, marble floors, gold accents.',
        'cyber-gallery'    => 'A dark futuristic exhibition space with neon light accents. For digital and web3 creators.',
    ];

    private const NEW_DESCRIPTIONS = [
        // Every sentence verifiable in a 60-second walk of the new render.
        'zen-gallery'      => 'A quiet, focused space: shoji screens, a tokonoma alcove and warm wood, tuned for close, calm looking.',
        'luxury-penthouse' => 'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.',
        'cyber-gallery'    => 'A dark electric space ringed with neon on every edge, the floor traced in light. For digital and web3 creators.',
    ];

    /**
     * visual_config keys to ADD per venue (absent keys only — admin edits win).
     * The structure arrays are the DB becoming the sole source of room
     * identity: JS interprets descriptors, it never knows venue names.
     */
    private function identityKeys(): array
    {
        $ZEN_STRUCTURE = [
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
        ];

        $PENTHOUSE_STRUCTURE = [
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
        ];

        $CYBER_STRUCTURE = [
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
        ];

        return [
            'white-cube' => [
                'structure_pass' => 'rooms',   // bespoke respect pass gate (§4.1)
            ],
            'zen-gallery' => [
                'structure_pass' => 'rooms',
                'structure'      => $ZEN_STRUCTURE,
            ],
            'luxury-penthouse' => [
                'structure_pass' => 'rooms',
                'glazing_wall'   => true,
                'structure'      => $PENTHOUSE_STRUCTURE,
            ],
            'cyber-gallery' => [
                'structure_pass' => 'rooms',
                'structure'      => $CYBER_STRUCTURE,
            ],
            'sculpture-garden' => [
                'sun_shadows' => true,         // high-tier-only sun shadows (§4.10)
            ],
        ];
    }

    /**
     * material_config keys to ADD (absent keys only).
     * floor_tile_meters: venue-declared texture tile density (metres) — the
     * code default is also 2 m; declaring it makes the White Cube / garden
     * intent explicit and admin-tunable (§4.1 floor-scale fix).
     */
    private function materialKeys(): array
    {
        return [
            'white-cube'       => ['floor_tile_meters' => 2.0],
            'sculpture-garden' => ['floor_tile_meters' => 2.0],
        ];
    }

    /**
     * default_settings keys to SET — guarded by exact match on the current
     * value (the museum's brick default becomes a painted wall; galleries
     * that already stored 'brick' keep it — this is the venue DEFAULT).
     */
    private function defaultSettingKeys(): array
    {
        return [
            'dark-museum' => ['wall_texture' => ['from' => 'brick', 'to' => 'white']],
        ];
    }

    public function up(): void
    {
        foreach ($this->identityKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue; // venue removed by the operator — respect that
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $merged   = $existing + $keys;
            if ($merged !== $existing) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($merged)]);
            }
        }

        foreach ($this->materialKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'material_config']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->material_config, true) ?: [];
            $merged   = $existing + $keys;
            if ($merged !== $existing) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($merged)]);
            }
        }

        foreach ($this->defaultSettingKeys() as $slug => $spec) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'default_settings']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->default_settings, true) ?: [];
            if (($existing['wall_texture'] ?? null) === $spec['wall_texture']['from']) {
                $existing['wall_texture'] = $spec['wall_texture']['to'];
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['default_settings' => json_encode($existing)]);
            }
        }

        foreach (self::OLD_DESCRIPTIONS as $slug => $old) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'description']);
            if (!$row) {
                continue;
            }
            if ($row->description === $old) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['description' => self::NEW_DESCRIPTIONS[$slug]]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::OLD_DESCRIPTIONS as $slug => $old) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'description']);
            if (!$row) {
                continue;
            }
            if ($row->description === self::NEW_DESCRIPTIONS[$slug]) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['description' => $old]);
            }
        }

        foreach ($this->defaultSettingKeys() as $slug => $spec) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'default_settings']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->default_settings, true) ?: [];
            if (($existing['wall_texture'] ?? null) === $spec['wall_texture']['to']) {
                $existing['wall_texture'] = $spec['wall_texture']['from'];
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['default_settings' => json_encode($existing)]);
            }
        }

        foreach ($this->materialKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'material_config']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->material_config, true) ?: [];
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                }
            }
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['material_config' => json_encode($existing)]);
        }

        foreach ($this->identityKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                }
            }
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['visual_config' => json_encode($existing)]);
        }
    }
};

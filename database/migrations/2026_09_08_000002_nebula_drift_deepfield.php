<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NEBULA DRIFT — "The Deep Field" identity pass (v1.0.0 → 2.0.0).
 * (2026-09-08. Predecessors: 2026_09_01_000002 "Phenomena", 2026_09_05
 * Infinite Void deepening, 2026_09_07 Crystal Cathedral audit.)
 *
 * WHY
 * ---
 * The forensic audit found a venue that answered the name test with
 * "a dark room with purple fog and stars" — the exact failure state the
 * brief forbids. Every defect below is fixed at the CONFIG layer (the DB
 * stays the only identity source); the renderer grows one new declared
 * body (void_deepfield) plus two generic, backward-compatible extensions
 * (post-placement light pools; a drift-rotate motion type).
 *
 *  N1  DOUBLE PURPLE LIGHT. The seeded fixture 'nebula-center'
 *      (PointLight 0x8844ff @ [0,5,0]) AND the procedural body each added
 *      an identical purple point light at the same position — plus the
 *      circular shell's white centre fill made THREE stacked centre
 *      lights. The rig becomes ONE declared cold key light.
 *  N2  ARTWORK COLOUR HONESTY. ambient 0x8844ff @ 0.2 tinted every lit
 *      artwork canvas purple (canvases are MeshStandardMaterial on high
 *      tier). Ambient becomes neutral moon-slate 0x7a86b8; the only warm
 *      light in the venue remains the pooled artwork lighting.
 *  N3  THE NEBULA WAS A PARTICLE BOX. 400 uniform additive points through
 *      the artwork zone (y −1..5) — confetti, not a nebula. Replaced by
 *      the declared void_deepfield body: tilted galactic-band sprite
 *      shells with density/scale variation, neutral star strata and
 *      colossal silhouettes.
 *  N4  EVERY STAR WAS VIOLET. hue 0.7–0.85 on all 800 stars. The new sky
 *      is white → blue-white with rare tints (§11 restraint).
 *  N5  NO SPATIAL ANCHORS / NO IDENTITY OF ITS OWN. The venue was
 *      Infinite Void + purple. The Deep Field adds layer, band, ring,
 *      pools and silhouettes — "something immense is moving around you".
 *  N6  ARTWORKS SWALLOWED BY THE DARK. artwork_light_base sat at the
 *      wall-venue 0.15 default (standing glow ≈ 0.29) though the hang
 *      floats beyond the proximity radius — the exact gap the Infinite
 *      Void audit documented; no pool cap. Declared 0.5 + cap 12 +
 *      spot 1.2 (pool target ≈ 4.2, in family with void 4.55 /
 *      cathedral 4.0).
 *  N7  FLOOR: the declared colour never reached the marble (texture_tint
 *      missing — the documented Infinite Void production bug class),
 *      metalness 0.5 rendered dead with env_intensity ≈ 0, and the disc
 *      ended at a hard geometric seam. texture_tint true, floor
 *      re-declared, metalness 0.15, floor_edge_fade true.
 *  N8  UNCONTROLLED POST-FX: stock bloom 0.6 + the legacy GREY vignette
 *      blend (the exact Cathedral deploy-review defect class). Declared:
 *      bloom 0.35 @ threshold 0.8, black-blend vignette.
 *  N9  ENVIRONMENT BY DRIFT: night.hdr was downloaded (and 404'd) while
 *      env_intensity 0.05 tried to silence it. Declared environment
 *      'none' — the sky is procedural, the download disappears (a
 *      time-to-walk win on 10 Mbps).
 *  N10 FLAT HANG AT SCALE: depth_bands 2 (the void-family consolidation
 *      key) so 40-work shows compose in depth instead of one huge ring.
 *
 * SAFETY (production data protection — the guarded pattern of every venue
 * pass; see 2026_09_07_000001 for the full rationale)
 * ---------------------------------------------------------------------
 *   • Changed values swap ONLY from the seeded v1.0.0 value to the new one
 *     — a super-admin retune survives.
 *   • Added keys are UNION-added (absent key only).
 *   • Removed keys (void_starfield) are removed ONLY while still equal to
 *     the seeded value; the legacy body stays in the JS as the rollback
 *     target (void_deepfield → void_starfield → nothing).
 *   • The lighting_fixtures array swaps only on an exact JSON match with
 *     the seeded v1.0.0 list.
 *   • The description swaps only on an exact match with the seeded v1.0.0
 *     copy.
 *   • Version swap exact-match guarded. Portable PHP read-modify-write;
 *     idempotent; down() restores the exact previous state under the same
 *     guards.
 *   • The venue_config cache re-keys from the row contents + SCHEMA, so
 *     the pass is live on the next render after migrate — no manual cache
 *     clear.
 */
return new class extends Migration
{
    private const SLUG = 'nebula-drift';

    private const OLD_VERSION = '1.0.0';
    private const NEW_VERSION = '2.0.0';

    private const OLD_DESCRIPTION =
        'Artworks drift through a cosmic cloud — distant stars and a purple nebula with quiet depth between them. For digital art and otherworldly exhibitions.';
    private const NEW_DESCRIPTION =
        'A deep-field nebula surrounds the exhibition — layered cosmic masses drifting along a tilted galactic band, a slow stardrift current, and a lone meridian ring overhead. Artworks float above pools of light on a dark starlit floor.';

    private const OLD_FIXTURES = [
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
    ];

    private const NEW_FIXTURES = [
        [
            'id'          => 'nebula-key',
            'type'        => 'directional',
            'position'    => [30, 45, -18],
            'color'       => '0x9ab0e0',
            'intensity'   => 0.45,
            'cast_shadow' => false,
        ],
    ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];

        // Changed values — only while still equal to the seeded v1.0.0 value.
        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $from) {
                $visual[$key] = $to;
            }
        }

        // Added keys — union (absent key only).
        foreach ($this->addedVisualKeys() as $key => $value) {
            if (!array_key_exists($key, $visual)) {
                $visual[$key] = $value;
            }
        }

        // Removed keys — only while still equal to the seeded value. The
        // legacy starfield body stays in the JS as the rollback target.
        foreach ($this->removedVisualKeys() as $key => $value) {
            if (($visual[$key] ?? null) === $value) {
                unset($visual[$key]);
            }
        }

        // Nested venue-owned objects — union-add when absent (s3/s6 rule:
        // the venue owns the whole object wholesale).
        if (!array_key_exists('nebula', $visual)) {
            $visual['nebula'] = [
                'dominant'  => '0x5a4ae0',
                'secondary' => '0x2e6ac8',
                'accent'    => '0xd85a9e',
            ];
        }
        if (!is_array($visual['post_fx'] ?? null)) {
            $visual['post_fx'] = [];
        }
        $visual['post_fx'] += [
            'bloom'             => true,
            'bloom_strength'    => 0.35,
            'bloom_threshold'   => 0.8,
            'bloom_radius'      => 0.35,
            'vignette'          => true,
            'vignette_darkness' => 0.55,
            'vignette_offset'   => 1.3,
            'vignette_blend'    => 'black',
        ];
        if (!is_array($visual['placement'] ?? null)) {
            $visual['placement'] = [];
        }
        $visual['placement'] += [
            'depth_bands' => 2,
            'light_pools' => true,
        ];

        // Material config — guarded changed + union-added.
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $from) {
                $material[$key] = $to;
            }
        }
        foreach ($materialChanges['added'] as $key => $value) {
            if (!array_key_exists($key, $material)) {
                $material[$key] = $value;
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        // Fixtures (N1) — exact-match swap of the seeded v1.0.0 list.
        if ($fixtures === self::OLD_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::NEW_FIXTURES);
        }

        // Copy — exact-match swap (the promise matrix: names what renders).
        if ((string) $row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
        }

        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];

        // Reverse exactly what up() wrote, under the same guards.

        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $to) {
                $visual[$key] = $from;
            }
        }

        foreach ($this->addedVisualKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        // Restore the superseded body flag (the pre-pass state).
        if (!array_key_exists('void_starfield', $visual)) {
            $visual['void_starfield'] = true;
        }

        if (($visual['nebula']['dominant'] ?? null) === '0x5a4ae0'
            && ($visual['nebula']['secondary'] ?? null) === '0x2e6ac8'
            && ($visual['nebula']['accent'] ?? null) === '0xd85a9e') {
            unset($visual['nebula']);
        }

        if (is_array($visual['post_fx'] ?? null)
            && ($visual['post_fx']['bloom_strength'] ?? null) === 0.35
            && ($visual['post_fx']['vignette_blend'] ?? null) === 'black') {
            unset($visual['post_fx']);
        }

        if (is_array($visual['placement'] ?? null)
            && ($visual['placement']['depth_bands'] ?? null) === 2
            && ($visual['placement']['light_pools'] ?? null) === true) {
            unset($visual['placement']);
        }

        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $to) {
                $material[$key] = $from;
            }
        }
        foreach ($materialChanges['added'] as $key => $value) {
            if (array_key_exists($key, $material) && $material[$key] === $value) {
                unset($material[$key]);
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        if ($fixtures === self::NEW_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::OLD_FIXTURES);
        }

        if ((string) $row->description === self::NEW_DESCRIPTION) {
            $update['description'] = self::OLD_DESCRIPTION;
        }

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    /**
     * visual_config values this migration REPLACES — guarded from → to.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changedVisualKeys(): array
    {
        return [
            // N2: the purple ambient tinted every lit artwork canvas.
            'ambient_color'         => ['from' => '0x8844ff', 'to' => '0x7a86b8'],
            // 0.55: at 40 works only 12 canvases carry a pool light — the
            // declared base wash keeps the other 28 legible (the spawn view
            // must never show dark rectangles).
            'ambient_intensity'     => ['from' => 0.2, 'to' => 0.55],
            // N6: the pool target ≈ 4.2 (void family: void 4.55, cathedral 4.0).
            'spot_intensity'        => ['from' => 0.55, 'to' => 1.2],
            // N8-adjacent: the rig is luminous, not murk.
            'tone_mapping_exposure' => ['from' => 0.6, 'to' => 0.85],
            // Fog survives 40-work scale (near/far retune).
            'fog_near'              => ['from' => 10, 'to' => 12],
            'fog_far'               => ['from' => 40, 'to' => 70],
            // N9: env_intensity 0 + environment 'none' = no HDRI, ever.
            'env_intensity'         => ['from' => 0.05, 'to' => 0],
        ];
    }

    /**
     * visual_config keys this migration ADDS — union (absent key only).
     *
     * @return array<string, mixed>
     */
    private function addedVisualKeys(): array
    {
        return [
            'environment'            => 'none',  // N9: the sky is procedural
            'floor_edge_fade'        => true,    // N7: the disc dissolves into the void
            'void_depth_gradient'    => true,    // the shared zenith depth cue, reused
            'void_deepfield'         => true,    // N3/N4/N5: the audit body
            'artwork_light_base'     => 0.5,     // N6: the void-family standing glow
            'artwork_light_pool_cap' => 12,      // N6: a 12-piece hang lit at once
            'hemisphere_intensity'   => 0.35,    // vertical fill for unlit far canvases
        ];
    }

    /**
     * visual_config keys this migration REMOVES — only while still equal to
     * the value the previous iteration seeded (the legacy body's flag; the
     * body itself stays in the JS as the rollback target).
     *
     * @return array<string, mixed>
     */
    private function removedVisualKeys(): array
    {
        return [
            'void_starfield' => true,
        ];
    }

    /**
     * material_config values this migration REPLACES (guarded the same way)
     * + keys it ADDS (union).
     *
     * @return array{changed: array<string, array{from: mixed, to: mixed}>, added: array<string, mixed>}
     */
    private function materialChanges(): array
    {
        return [
            'changed' => [
                // N7: the marble obeys the declaration; metal without an
                // environment renders dead.
                'floor_color'     => ['from' => '0x100525', 'to' => '0x0b0724'],
                'floor_roughness' => ['from' => 0.3, 'to' => 0.32],
                'floor_metalness' => ['from' => 0.5, 'to' => 0.15],
            ],
            'added' => [
                // N7: without this flag a declared floor colour never
                // reaches textured builds (the documented IV bug class).
                'texture_tint' => true,
            ],
        ];
    }
};

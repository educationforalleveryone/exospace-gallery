<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRYSTAL CATHEDRAL — "The Luminous Arcade" architectural identity pass.
 * (Venue-deepening iteration, 2026-09-07. Predecessor: Iteration 2
 * "Phenomena" 2026_09_01_000002, Iteration 6 "Consolidation" 000005.)
 *
 * WHY
 * ---
 * The forensic audit of the venue found a name/delivery gap: the seeded
 * body rendered TWELVE THIN SMOOTH-SHADED GLASS TUBES (8-segment cylinders,
 * 0.3–0.48 m radius, 9–15 m tall) in a ring outside the artwork field plus
 * FOUR PASTEL RAINBOW point lights (0xffaaaa / 0xaaffaa / 0xaaaaff …).
 * Shown without its name, the venue did not read as "Crystal Cathedral" —
 * it read as Infinite Void with a fence of glass rods and nightclub accents.
 * Crystal existed as DECORATION (§7 violation); there was no nave rhythm,
 * no arch, no vault, no light from above, no artwork presentation language
 * distinct from Nebula Drift, and the colonnade did not scale with the
 * exhibition radius (12 pillars at a 22 m ring ≈ 12 m of empty darkness
 * between neighbours).
 *
 * WHAT IT SHIPS (data side — the JS body `addCrystalCathedralArcade`
 * interprets these keys; the DB stays the single source of identity)
 * ---------------------------------------------------------------------
 *   void_arcade        = true   → the new composed body: an adaptive
 *                                 colonnade of faceted crystal piers
 *                                 carrying POINTED ARCHES, a framed stone
 *                                 art-bay ring, a clerestory crystal band
 *                                 under a luminous seam, and a radial rib
 *                                 vault converging on a luminous oculus.
 *   void_colonnade     (removed)→ the Iteration 2 glass-tube ring stays in
 *                                 the JS as the per-venue ROLLBACK body —
 *                                 reachable again by re-adding this key.
 *   colonnade_tint     retuned  → 0xdfeaff → 0xe6f0fb (ice-white crystal;
 *                                 the blue lives in the atmosphere, not the
 *                                 material — colour restraint, §20).
 *   Rig (physical-units retune) → ambient 0.25→0.34 @ 0xbfd4ec, spot
 *                                 0.5→1.15 (pool target 3.5→4.0), fill
 *                                 0.15→0.22, exposure 0.6→0.85: luminous,
 *                                 not dark; artworks stay the hero.
 *   atmosphere         → background/fog 0x070b14, fog 18→62 (the arcade
 *                                 must not dissolve at 40-artwork scale).
 *   environment        = 'studio' + env_intensity 0.22 → the glass'
 *                                 reflections are DECLARED (were an
 *                                 accident of the preset's studio.hdr).
 *   hemisphere_intensity = 0.22 → vertical gradient cue (sky above,
 *                                 dark below).
 *   void_depth_gradient  = true → zenith depth cue above the vault.
 *   artwork_light_base = 0.38  + artwork_light_pool_cap = 12 → the void
 *                                 family's standing glow (infinite-void
 *                                 already declares 0.45/12; the cathedral
 *                                 ring sat at the 0.15 wall-venue default).
 *   placement          = ['depth_bands' => 2] → curation opt-in (the
 *                                 IT6 allowlist widens by this migration):
 *                                 12+ works compose in TWO depth rings so
 *                                 40-piece shows stay inside ~17 m radius
 *                                 instead of sprawling to 22 m.
 *   post_fx            → declared restraint: bloom ON at strength 0.42 /
 *                                 threshold 0.82 (catches the oculus and
 *                                 the clerestory seam only), black-blend
 *                                 vignette 0.62. Previously UNDECLARED —
 *                                 the stock 0.6/0.85 bloom rode in.
 *   material_config    → the art-bay stone (wall_*) 0x131a26 / 0.3 / 0.06,
 *                                 slate floor 0x1a2230 / 0.22 / 0.2,
 *                                 floor_tile_meters 2.5, texture_tint true
 *                                 (declared colours authoritative over the
 *                                 marble texture).
 *
 * NOT in this migration (deliberate): floor_reflection stays UNDECLARED in
 * the data... it is ADDED (see addedKeys) — 'planar' — because the copy now
 * promises a reflection and the IT2 honesty rule ("copy ⇔ declared render")
 * is enforced by test. High tier renders a real Reflector; mobile/low-end
 * get the designed gloss mood (TierResolve).
 *
 * COPY (guarded, honesty-matrix compliant)
 * ----------------------------------------
 * The new description contains: "colonnade" (the verticality gate),
 * "float" (placement_mode promise) and "reflect" (the planar reflector
 * promise). Every claim renders: piers, pointed arches, polished dark
 * stone, vaulted oculus, falling light, reflection, framed bays.
 *
 * SAFETY (production data protection — IT2 pattern)
 * -------------------------------------------------
 *   • CHANGED values (rig, tint, fog, materials) apply ONLY when the current
 *     value still equals the seeded value — a super-admin retune survives.
 *   • ADDED keys merge by UNION (absent keys only).
 *   • REMOVED keys (void_colonnade) are removed ONLY while still equal to
 *     the seeded value.
 *   • Description/version/tags swaps are exact-match guarded.
 *   • Portable PHP read-modify-write (no MySQL JSON functions); idempotent.
 *   • down() restores the exact previous seeded state under the same guards.
 *   • The venue_config cache re-keys automatically (venueSignature sha1 over
 *     visual_config/material_config/description-adjacent columns), so the
 *     new identity is live on the next render after migrate — no manual
 *     cache clear.
 */
return new class extends Migration
{
    private const SLUG = 'crystal-cathedral';

    private const OLD_DESCRIPTION =
        'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.';
    private const NEW_DESCRIPTION =
        'A colonnade of faceted crystal piers carries pointed arches around a hall of polished dark stone; light falls from a vaulted oculus and reflects across the floor while artworks float before framed bays of stone.';

    private const OLD_VERSION = '1.0.0';
    private const NEW_VERSION = '2.0.0';

    private const OLD_TAGS = ['glass', 'crystal', 'ethereal', 'refraction'];
    private const NEW_TAGS = ['crystal', 'colonnade', 'luminous', 'ethereal'];

    /**
     * visual_config values this migration REPLACES — applied only while the
     * row still carries the seeded value (admin edits win).
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changedValues(): array
    {
        return [
            'wall_height'           => ['from' => 12,          'to' => 13],
            'background_color'      => ['from' => '0x0a0a1a',  'to' => '0x070b14'],
            'fog_color'             => ['from' => '0x0a0a1a',  'to' => '0x070b14'],
            'fog_near'              => ['from' => 15,          'to' => 18],
            'fog_far'               => ['from' => 50,          'to' => 62],
            'ambient_color'         => ['from' => '0xddeeff',  'to' => '0xbfd4ec'],
            'ambient_intensity'     => ['from' => 0.25,        'to' => 0.34],
            'spot_intensity'        => ['from' => 0.5,         'to' => 1.15],
            'fill_intensity'        => ['from' => 0.15,        'to' => 0.22],
            'tone_mapping_exposure' => ['from' => 0.6,         'to' => 0.85],
            'colonnade_tint'        => ['from' => '0xdfeaff',  'to' => '0xe6f0fb'],
        ];
    }

    /**
     * visual_config keys this migration ADDS — absent keys only (union).
     *
     * @return array<string, mixed>
     */
    private function addedKeys(): array
    {
        return [
            // The new composed architecture body (rollback chain:
            // void_arcade → void_colonnade → void_shards).
            'void_arcade'            => true,
            // Declared environment (was an accident of the preset's HDRI).
            'environment'            => 'studio',
            'env_intensity'          => 0.22,
            // Vertical gradient cues (the "look up" mandate).
            'hemisphere_intensity'   => 0.22,
            'void_depth_gradient'    => true,
            // Void-family artwork legibility (standing glow + pool raise).
            'artwork_light_base'     => 0.38,
            'artwork_light_pool_cap' => 12,
            // Curation opt-in: two depth rings past 12 works.
            'placement'              => ['depth_bands' => 2],
            // Declared post-processing restraint (was undeclared stock bloom).
            'post_fx'                => [
                'bloom'             => true,
                'bloom_strength'    => 0.42,
                'bloom_threshold'   => 0.82,
                'bloom_radius'      => 0.35,
                'vignette'          => true,
                'vignette_darkness' => 0.62,
                'vignette_offset'   => 1.15,
            ],
            // The copy now promises the reflection — the config must declare
            // it (honesty matrix; tiered by TierResolve: planar/gloss/none).
            'floor_reflection'       => 'planar',
        ];
    }

    /**
     * visual_config keys this migration REMOVES — only while still equal to
     * the value the previous iteration seeded (the legacy body's flag; the
     * body itself stays in the JS as the rollback target).
     *
     * @return array<string, mixed>
     */
    private function removedKeys(): array
    {
        return [
            'void_colonnade' => true,
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
                'wall_color'            => ['from' => '0x202030', 'to' => '0x131a26'],
                'wall_roughness'        => ['from' => 0.2,        'to' => 0.3],
                'wall_metalness'        => ['from' => 0.0,        'to' => 0.06],
                'wall_normal_strength'  => ['from' => 0.3,        'to' => 0.25],
                'floor_color'           => ['from' => null,       'to' => '0x1a2230'],
                'floor_roughness'       => ['from' => 0.1,        'to' => 0.22],
                'floor_metalness'       => ['from' => 0.4,        'to' => 0.2],
                'floor_normal_strength' => ['from' => 0.3,        'to' => 0.25],
            ],
            'added' => [
                'floor_tile_meters' => 2.5,
                'texture_tint'      => true,
            ],
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'description', 'version', 'tags']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // 1. Guarded value replacements (seeded values only).
        foreach ($this->changedValues() as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $visual) && $visual[$key] === $from) {
                $visual[$key] = $to;
            }
        }

        // 2. Union-merge added keys (absent keys only — admin edits win).
        $visual += $this->addedKeys();

        // 3. Guarded removal of the superseded body flag.
        foreach ($this->removedKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        $material = json_decode((string) $row->material_config, true) ?: [];
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $material) && $material[$key] === $from) {
                $material[$key] = $to;
            }
        }
        $material += $materialChanges['added'];

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        // 4. Guarded copy / version / tags (admin-customized values kept).
        if ($row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
        }
        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }
        if ((array) json_decode((string) $row->tags, true) === self::OLD_TAGS) {
            $update['tags'] = json_encode(self::NEW_TAGS);
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'description', 'version', 'tags']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Reverse replacements — only where the value is still what up() wrote.
        foreach ($this->changedValues() as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $visual) && $visual[$key] === $to) {
                $visual[$key] = $from;
            }
        }

        // Remove exactly the keys up() added (only where unchanged).
        foreach ($this->addedKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        // Restore the superseded body flag (the pre-pass state).
        if (!array_key_exists('void_colonnade', $visual)) {
            $visual['void_colonnade'] = true;
        }

        $material = json_decode((string) $row->material_config, true) ?: [];
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $material) && $material[$key] === $to) {
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

        if ($row->description === self::NEW_DESCRIPTION) {
            $update['description'] = self::OLD_DESCRIPTION;
        }
        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }
        if ((array) json_decode((string) $row->tags, true) === self::NEW_TAGS) {
            $update['tags'] = json_encode(self::OLD_TAGS);
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }
};

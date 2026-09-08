<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NEBULA DRIFT — "the nebula owns the sky" identity pass (v2.1.0 → 2.2.0).
 * (2026-09-08. Predecessors: 2026_09_08_000002 "Deep Field", 2026_09_08_000003
 * "arch/deploy-review" — same trigger as the cathedral's two-round history:
 * the deployed frame kept failing the visual review the sandbox had passed.)
 *
 * WHY (the v2.1.0 sandbox frames' verdict was still NEEDS REFINEMENT)
 * -------------------------------------------------------------------
 * The arch fixed the band's GEOMETRY (tilt 55–62°, crown-biased azimuths)
 * but not its PRESENCE. Captured evidence, three poses:
 *   • the band read as one soft corner glow patch — ~80% of the sky stayed
 *     empty black; every mass rendered at the same mid opacity, so the sky
 *     had no luminosity hierarchy and the eye found no core;
 *   • the meridian ring was the LOUDEST object in the sky — a bright white
 *     hoop, reading exactly like the debug wireframe the brief forbids;
 *   • the monolith silhouettes were invisible in every pose (0x0d0a22 under
 *     a 0.45 key models nothing — they silhouetted against nothing);
 *   • the hang sat as flat rings at desk height — "pictures floating in
 *     space", not "an exhibition suspended inside a cosmic environment".
 *
 * WHAT CHANGES
 * ------------
 * The renderer's void_deepfield body grows the composition craft (behind
 * the SAME flag — the body is the rollback unit):
 *   K1  a crown luminosity profile — feature masses brighten toward the
 *       arch core (×0.7 … ×1.15, pure function of the seeded azimuth);
 *   K2  the backbone haze lifts +2 puffs and +0.1 opacity per shell so the
 *       arch reads as one continuous river of luminosity, not blobs;
 *   K3  a NEAR VEIL — four huge whisper-soft masses at R×1.55 overhead,
 *       fastest precession: walking now shears the sky in true parallax
 *       (far arch → mid band → near veil), the drift you stand INSIDE;
 *   K4  monoliths lift to 0x141032 so the key light models their facets;
 *   K6  the ring demotes to a thread — base luminosity 0.6 → 0.3 (whisper
 *       circle), arcs become narrow hot beads just over the bloom threshold
 *       (travelling light), material opacity 0.85 → 0.55;
 *   K7  the ring halo gathers atmosphere (nebula-tinted, doubled presence).
 *
 * This migration carries the CONFIG side — the DB stays the only identity
 * source:
 *   K8  artwork_light_base 0.62 → 0.72 — the standing pool glow breathes
 *       brighter so every canvas reads as a lit island (§3 protagonism).
 *       Colour honesty unchanged: ambient stays moon-slate, pools stay the
 *       venue's only warm light.
 *   K9  placement.elevation_step 0.7 — a NEW generic placement key
 *       (PlacementMath default 0 = bit-exact historic behaviour for every
 *       other venue): each inner depth band hovers higher, so the hang
 *       composes vertically — a suspended constellation, not flat rings.
 *
 * SAFETY (the guarded pattern of every venue pass)
 * -------------------------------------------------
 *   • Changed values swap ONLY from the seeded v2.1.0 value — a
 *     super-admin retune survives.
 *   • The new placement key is UNION-added inside the existing placement
 *     object (absent key only; the object itself arrived with v2.0.0).
 *   • Description + version swap exact-match guarded. Idempotent; down()
 *     restores the exact previous state under the same guards.
 *   • The venue_config cache re-keys from the row contents + SCHEMA, so
 *     the pass is live on the next render after migrate.
 */
return new class extends Migration
{
    private const SLUG = 'nebula-drift';

    private const OLD_VERSION = '2.1.0';
    private const NEW_VERSION = '2.2.0';

    private const OLD_DESCRIPTION =
        'A deep-field nebula arches over the exhibition — immense cosmic masses wheeling slowly overhead along a galactic band, a stardrift current, and a meridian ring of travelling light. Artworks float above pools of light on a floor that dissolves into the void.';
    private const NEW_DESCRIPTION =
        'A deep-field nebula owns the sky — one immense galactic arch with a luminous core wheeling overhead, colossal silhouettes at its edges, a stardrift current, and a meridian thread of travelling light. Artworks hang as a suspended constellation over pools of light, the floor dissolving into the void.';

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Changed values — only while still equal to the seeded v2.1.0 value.
        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $from) {
                $visual[$key] = $to;
            }
        }

        // Added keys — union (absent key only). placement is a nested
        // venue-owned object (arrived with the v2.0.0 pass); the new key
        // joins it under the same union rule.
        foreach ($this->addedVisualKeys() as $key => $value) {
            if (!array_key_exists($key, $visual)) {
                $visual[$key] = $value;
            }
        }
        if (is_array($visual['placement'] ?? null) && !array_key_exists('elevation_step', $visual['placement'])) {
            $visual['placement']['elevation_step'] = 0.7;
        }

        $update = ['visual_config' => json_encode($visual)];

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
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

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
        if (is_array($visual['placement'] ?? null)
            && ($visual['placement']['elevation_step'] ?? null) === 0.7) {
            unset($visual['placement']['elevation_step']);
        }

        $update = ['visual_config' => json_encode($visual)];

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
            // K8: the standing pool glow — artworks read as lit islands at
            // spawn distance (12–20 m). Pool target ≈ 4.7 × 0.72 ≈ 3.4.
            'artwork_light_base' => ['from' => 0.62, 'to' => 0.72],
        ];
    }

    /**
     * visual_config keys this migration ADDS — union (absent key only).
     * (placement.elevation_step is handled separately — nested union.)
     *
     * @return array<string, mixed>
     */
    private function addedVisualKeys(): array
    {
        return [];
    }
};

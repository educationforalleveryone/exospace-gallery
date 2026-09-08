<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NEBULA DRIFT — deploy-review identity pass (v2.0.0 → 2.1.0).
 * (2026-09-08. Predecessors: 2026_09_08_000002 "Deep Field", the cathedral
 * deploy-review 2026_09_08_000001 — same trigger: the production frame
 * failed the visual review the sandbox QA had passed.)
 *
 * WHY (the deployed spawn frame's verdict was NEEDS REFINEMENT)
 * ------------------------------------------------------------
 * The production screenshot read as "Infinite Void rendered in blue": the
 * galactic band hugged the horizon plane (tilt 22–27°), sat below the
 * perception threshold (texture body × material opacity ≈ 0.10–0.17), and
 * left the overhead sky empty; the "hard horizon line" was the floor fade
 * ring's phantom plane (out to 2.2·R, background 0x050015) clipping the
 * band's below-horizon glow against the pure-black dome; the meridian ring
 * read as a bare wireframe; monoliths silhouetted against nothing; the
 * light pools were too faint to compose the floor.
 *
 * This migration changes ONLY declared config values — the body fixes
 * (arch tilt + azimuth bias, same-sense precession on the tilt groups,
 * ring vertex luminosity + halo, monolith/arch tie, stronger pools,
 * texture rework) ship in VenueDecorator behind the SAME void_deepfield
 * flag. The DB stays the only identity source.
 *
 *  R1  THE BAND MUST ARCH. bandTilt is body-side; the config contributes
 *      the seam-free sky: background/fog 0x050015 → 0x000000 so the floor
 *      dissolve meets the dome's black horizon EXACTLY (the 0x050015 vs
 *      0x000000 luminance step was the visible line), and floor_fade_span
 *      1.16 ends the phantom plane at the dissolve so the void wraps
 *      beneath the exhibition instead of a stage edge.
 *  R2  ARTWORK PRESENCE AT DISTANCE. ambient 0.55 → 0.62, spot 1.2 → 1.35
 *      (pool target ≈ 4.7), artwork_light_base 0.5 → 0.62 — the spawn view
 *      is 12–20 m from the outer ring; the wash must carry unlit canvases
 *      at that distance. Colour honesty unchanged (moon-slate ambient).
 *
 * SAFETY (the guarded pattern of every venue pass)
 * ------------------------------------------------
 *   • Changed values swap ONLY from the seeded v2.0.0 value — a
 *     super-admin retune survives.
 *   • Added keys are UNION-added (absent key only).
 *   • Description + version swap exact-match guarded. Idempotent; down()
 *     restores the exact previous state under the same guards.
 *   • The venue_config cache re-keys from the row contents + SCHEMA, so
 *     the pass is live on the next render after migrate.
 */
return new class extends Migration
{
    private const SLUG = 'nebula-drift';

    private const OLD_VERSION = '2.0.0';
    private const NEW_VERSION = '2.1.0';

    private const OLD_DESCRIPTION =
        'A deep-field nebula surrounds the exhibition — layered cosmic masses drifting along a tilted galactic band, a slow stardrift current, and a lone meridian ring overhead. Artworks float above pools of light on a dark starlit floor.';
    private const NEW_DESCRIPTION =
        'A deep-field nebula arches over the exhibition — immense cosmic masses wheeling slowly overhead along a galactic band, a stardrift current, and a meridian ring of travelling light. Artworks float above pools of light on a floor that dissolves into the void.';

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Changed values — only while still equal to the seeded v2.0.0 value.
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
            // R1: the floor dissolve must meet the dome's black horizon
            // exactly — the 0x050015 step against the 0x000000 dome was the
            // deployed frame's hard horizon line.
            'background_color'   => ['from' => '0x050015', 'to' => '0x000000'],
            'fog_color'          => ['from' => '0x050015', 'to' => '0x000000'],
            // R2: the spawn view is 12–20 m from the outer ring — the wash
            // must carry unlit canvases at that distance.
            'ambient_intensity'  => ['from' => 0.55, 'to' => 0.62],
            'spot_intensity'     => ['from' => 1.2, 'to' => 1.35],
            'artwork_light_base' => ['from' => 0.5, 'to' => 0.62],
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
            // R1: the phantom ground plane ends at the dissolve (see
            // RoomBuilder's floor_fade_span consumer) so the void wraps
            // beneath the exhibition and the sky glow is never clipped.
            'floor_fade_span' => 1.16,
        ];
    }
};

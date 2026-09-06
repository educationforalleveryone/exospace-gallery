<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INDUSTRIAL LOFT DEEPENING — forensic-audit remediation for the pro flagship
 * venue (slug: industrial-loft). v1.0.0 → v2.0.0.
 *
 * WHAT THE AUDIT FOUND (screenshot-verified, not taste):
 *   • The rig was the catalog's last pre-polish numbers (exposure 0.55,
 *     ambient 0.18, spot 0.5, fill 0.15 — the exact profile the White Cube
 *     audit measured at ~10× too dim under r155+ physical light units).
 *     The venue rendered as a near-black tunnel; screenshots show artworks
 *     invisible and two track-light dots as the only bright content.
 *   • fog 8→35 m dissolved a 16 m+ room into murk at mid-distance.
 *   • post_fx was never declared, so the runtime default ran BLOOM ON in a
 *     venue of emissive lamps and night glass.
 *   • The dark venue kept the generic 0.15 artwork standing glow — the hang
 *     read as unlit rectangles (see also the ArtworkPlacer wall-burial fix
 *     that shipped in the same iteration's bundle: this venue's 0.5 m walls
 *     hid every artwork 5 cm inside the wall box).
 *   • The default corridor layout contradicted the venue's own promise of
 *     "large open spaces" at 30–80 works (a 6 m × 108 m linear tunnel at
 *     capacity, far half beyond the fog).
 *
 * THIS MIGRATION (DB side only — the JS identity ships in the bundle):
 *   visual_config : rig in physical units, fog reach, post_fx declaration,
 *                   artwork_light_base + pool cap, env_intensity,
 *                   frame_override 'black', corridor_width 9
 *   material_config: floor_tile_meters 3.0, floor_roughness 0.9→0.8,
 *                   normal strengths eased (0.8/0.7 → 0.65/0.6)
 *   default_settings: room_layout corridor→square
 *
 * GUARDING (same contract as the IT3/IT6/white-cube-polish migrations):
 * every rewrite fires ONLY while the stored value still equals the
 * previously seeded value (strings strictly, numbers numerically). A
 * super-admin's custom value is never touched. Absent keys (post_fx,
 * artwork_light_base, corridor_width, floor_tile_meters) are added only
 * when missing. Idempotent; down() reverses each rewrite under the same
 * exact-match guard.
 *
 * NOTE: this migration is paired with the seeder (fresh-install baseline).
 */
return new class extends Migration
{
    /**
     * Exact-match guard: strings strictly, numbers numerically (null never
     * matches). Keeps an admin's custom value from ever matching the seeded
     * "from" value the rewrite is guarded on.
     */
    private function guardedEquals($current, $from): bool
    {
        if ($current === null) {
            return false;
        }
        if (is_string($from)) {
            return is_string($current) && $current === $from;
        }
        return is_numeric($current) && (float) $current === (float) $from;
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'industrial-loft')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'fog_near'              => ['from' => 8,     'to' => 14],
            'fog_far'               => ['from' => 35,    'to' => 55],
            'ambient_intensity'     => ['from' => 0.18,  'to' => 0.55],
            'spot_intensity'        => ['from' => 0.5,   'to' => 2.4],
            'fill_intensity'        => ['from' => 0.15,  'to' => 1.1],
            'tone_mapping_exposure' => ['from' => 0.55,  'to' => 0.9],
            'frame_override'        => ['from' => null,  'to' => 'black'],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            // frame_override's seeded value IS null — the guard must accept
            // an explicit stored null for that one key only.
            if ($from === null) {
                if (!array_key_exists($key, $vc) || $vc[$key] === null) {
                    $vc[$key] = $to;
                }
                continue;
            }
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 0.35,
                'vignette_offset'   => 1.0,
            ];
        }
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.22;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 12;
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0.25;
        }
        if (!array_key_exists('corridor_width', $vc)) {
            $vc['corridor_width'] = 9;
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config ──────────────────────────────────────────────
        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.9)) {
            $mc['floor_roughness'] = 0.8;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.8)) {
            $mc['wall_normal_strength'] = 0.65;
        }
        if ($this->guardedEquals($mc['floor_normal_strength'] ?? null, 0.7)) {
            $mc['floor_normal_strength'] = 0.6;
        }
        if (!array_key_exists('floor_tile_meters', $mc)) {
            $mc['floor_tile_meters'] = 3.0;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── default_settings ─────────────────────────────────────────────
        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['room_layout'] ?? null) === 'corridor') {
            $ds['room_layout'] = 'square';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'industrial-loft')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'fog_near'              => ['from' => 14,    'to' => 8],
            'fog_far'               => ['from' => 55,    'to' => 35],
            'ambient_intensity'     => ['from' => 0.55,  'to' => 0.18],
            'spot_intensity'        => ['from' => 2.4,   'to' => 0.5],
            'fill_intensity'        => ['from' => 1.1,   'to' => 0.15],
            'tone_mapping_exposure' => ['from' => 0.9,   'to' => 0.55],
            'frame_override'        => ['from' => 'black', 'to' => null],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove the added keys only while they still equal what up() wrote.
        if (($vc['post_fx'] ?? null) === [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.35,
            'vignette_offset'   => 1.0,
        ]) {
            unset($vc['post_fx']);
        }
        foreach (['artwork_light_base' => 0.22, 'artwork_light_pool_cap' => 12, 'env_intensity' => 0.25, 'corridor_width' => 9] as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.8)) {
            $mc['floor_roughness'] = 0.9;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.65)) {
            $mc['wall_normal_strength'] = 0.8;
        }
        if ($this->guardedEquals($mc['floor_normal_strength'] ?? null, 0.6)) {
            $mc['floor_normal_strength'] = 0.7;
        }
        if (($mc['floor_tile_meters'] ?? null) === 3.0 || ($mc['floor_tile_meters'] ?? null) === 3) {
            unset($mc['floor_tile_meters']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['room_layout'] ?? null) === 'square') {
            $ds['room_layout'] = 'corridor';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        // ── key adds — removed only while they still equal what up() wrote
        // (an admin's edited value is never touched by down()).
        // NOTE: re-fetch - the in-memory $row predates the rewrites above and
        // must not be written back (that would undo them).
        $fresh = DB::table('venue_templates')->where('id', $row->id)->first(['id', 'visual_config']);
        $vc = json_decode((string) $fresh->visual_config, true) ?: [];
        $addedPostFx = [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.35,
            'vignette_offset'   => 1.0,
        ];
        if (isset($vc['post_fx']) && is_array($vc['post_fx'])
            && count($vc['post_fx']) === count($addedPostFx)
            && $this->arrayEqualsNumeric($vc['post_fx'], $addedPostFx)) {
            unset($vc['post_fx']);
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['visual_config' => json_encode($vc)]);
        }
        foreach ([
            'artwork_light_base'     => 0.22,
            'artwork_light_pool_cap' => 12,
        ] as $key => $added) {
            if (isset($vc[$key]) && $this->guardedEquals($vc[$key], $added)) {
                unset($vc[$key]);
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($vc)]);
            }
        }
    }

    /** Numeric-tolerant array equality (JSON int/float normalisation). */
    private function arrayEqualsNumeric(array $a, array $b): bool
    {
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a)) {
                return false;
            }
            if (is_bool($v)) {
                if ($a[$k] !== $v) {
                    return false;
                }
            } elseif (is_string($v)) {
                if ($a[$k] !== $v) {
                    return false;
                }
            } elseif (!is_numeric($a[$k]) || (float) $a[$k] !== (float) $v) {
                return false;
            }
        }
        return true;
    }
};

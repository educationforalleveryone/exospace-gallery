<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OUTDOOR SCULPTURE GARDEN v3.0.0 — "The Curated Walk" (landscape identity).
 *
 * WHAT THE FORENSIC AUDIT FOUND (screenshot-class defects, not taste):
 *   • The camera spawned at (0, 1.6, 0) — the exact spot where the §4.10
 *     redesign later placed the central pedestal + bronze knot. The
 *     visitor's first frame rendered from INSIDE the hero sculpture's AABB.
 *   • Every artwork hung on ONE evenly-spaced ring facing the centre (the
 *     legacy circular placer): no depth, no hierarchy, no discovery — from
 *     the spawn all pieces were visible at once (the metronome fence).
 *   • Easel-ring artworks registered NO collision obstacle (the float venues
 *     do) — visitors clipped straight through canvases.
 *   • The ground was a perfectly flat disc; the grass ended at a hard seam
 *     against the sky dome; beyond the hedge there was NOTHING (the horizon
 *     revealed the illusion).
 *   • Vegetation was 4 identical cone trees in the hedge's exact colour.
 *   • The "stone path" was 6 decorative discs connecting nothing.
 *   • No environment declaration → the resolved 'bright' preset's studio.hdr
 *     (an INTERIOR) downloaded and reflected in the bronze hero; a "ceiling"
 *     point light floated at (0, 8, 0) over the open sky; warm indoor pool
 *     lights hovered at every easel in daylight; the hemisphere light was
 *     neutral white/gray over grass.
 *
 * THE SIGNATURE (ships in the JS bundle — GardenLayout.js + the rebuilt
 * VenueDecorator garden body + ArtworkPlacer's 'garden' placement mode; this
 * migration carries only the DB half, the same split every deepening
 * iteration uses):
 *   the garden becomes a DESIGNED LANDSCAPE — terrain → walks → courts →
 *   vegetation, in that order. Artworks stand on curated clearings (primary /
 *   secondary / transitional), each facing its approach; a stone promenade
 *   leads from a garden GATE (the new spawn) to the bronze centrepiece and
 *   on around a ring walk; vegetation frames and screens; the horizon
 *   dissolves into a rolling, hazed distant landscape.
 *
 * THIS MIGRATION (DB side only):
 *   visual_config : declared environment absence + sky IBL strength, the
 *                   hemisphere sky/ground daylight tints, the ceiling-orb
 *                   opt-out, the landscape field sizing (bonus + floor), the
 *                   'garden' placement mode, the garden tuning block
 *                   (sky_environment), haze fog, rig rebalance.
 *   description   : verifiable copy (the v2 "winding path" copy is gone).
 *   version       : 2.0.0 → 3.0.0 under guard.
 *
 * GUARDING (same contract as the IT3/IT6/dark-museum/zen/cyber migrations):
 *   every rewrite fires ONLY while the stored value still equals the
 *   previously seeded value (strings strictly, numbers numerically; nulls
 *   only via explicit absence checks). A super-admin's custom value is never
 *   touched. Absent keys are added only when missing. Idempotent; down()
 *   reverses each rewrite under the same exact-match guard. Paired with the
 *   seeder (fresh-install baseline).
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
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color'       => ['from' => '0x87ceeb', 'to' => '0xd6e0e2'],
            'ambient_intensity'      => ['from' => 0.4,  'to' => 0.16],
            'tone_mapping_exposure'  => ['from' => 0.7,  'to' => 0.9],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // fog_color was an explicit null (→ "no fog"). The distant landscape
        // needs aerial perspective — rewrite only while it is still null.
        if (!array_key_exists('fog_color', $vc) || $vc['fog_color'] === null) {
            $vc['fog_color'] = '0xd6e0e2';
        }
        if ($this->guardedEquals($vc['fog_near'] ?? null, 0)) {
            $vc['fog_near'] = 18;
        }
        if ($this->guardedEquals($vc['fog_far'] ?? null, 0)) {
            $vc['fog_far'] = 45;
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('environment', $vc)) {
            $vc['environment'] = 'none';
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0.22;
        }
        if (!array_key_exists('hemisphere_intensity', $vc)) {
            $vc['hemisphere_intensity'] = 0.4;
        }
        if (!array_key_exists('hemisphere_sky_color', $vc)) {
            $vc['hemisphere_sky_color'] = '0xbfd9ee';
        }
        if (!array_key_exists('hemisphere_ground_color', $vc)) {
            $vc['hemisphere_ground_color'] = '0x51663c';
        }
        if (!array_key_exists('ceiling_fill_light', $vc)) {
            $vc['ceiling_fill_light'] = false;
        }
        if (!array_key_exists('field_radius_bonus', $vc)) {
            $vc['field_radius_bonus'] = 2.2;
        }
        if (!array_key_exists('field_radius_min', $vc)) {
            $vc['field_radius_min'] = 12.5;
        }
        if (!array_key_exists('placement_mode', $vc)) {
            $vc['placement_mode'] = 'garden';
        }
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.22;
        }
        if (!array_key_exists('garden', $vc)) {
            $vc['garden'] = ['sky_environment' => true];
        }
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 0.42,
                'vignette_offset'   => 1.15,
                'vignette_blend'    => 'black',
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── description (customer-facing truth) ──────────────────────────
        $v2Description = 'Open-air garden exhibition. Hedges, trees, sky, and stone paths. Artworks on easels along a winding path.';
        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        if ((string) $row->description === $v2Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v3Description]);
        }

        // ── version ──────────────────────────────────────────────────────
        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '3.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color'       => ['from' => '0xd6e0e2', 'to' => '0x87ceeb'],
            'ambient_intensity'      => ['from' => 0.26, 'to' => 0.4],
            'tone_mapping_exposure'  => ['from' => 0.72, 'to' => 0.7],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        if (($vc['fog_color'] ?? null) === '0xd6e0e2') {
            $vc['fog_color'] = null;
        }
        if (($vc['fog_near'] ?? null) === 18 || ($vc['fog_near'] ?? null) === 18.0) {
            $vc['fog_near'] = 0;
        }
        if (($vc['fog_far'] ?? null) === 45 || ($vc['fog_far'] ?? null) === 45.0) {
            $vc['fog_far'] = 0;
        }

        // Remove the added keys only while they still equal what up() wrote.
        $seededAdds = [
            'environment'             => 'none',
            'env_intensity'           => 0.22,
            'hemisphere_intensity'    => 0.4,
            'hemisphere_sky_color'    => '0xbfd9ee',
            'hemisphere_ground_color' => '0x51663c',
            'ceiling_fill_light'      => false,
            'field_radius_bonus'      => 2.2,
            'field_radius_min'        => 12.5,
            'placement_mode'          => 'garden',
            'artwork_light_base'      => 0.22,
        ];
        foreach ($seededAdds as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        $seededGarden = ['sky_environment' => true];
        if (($vc['garden'] ?? null) === $seededGarden) {
            unset($vc['garden']);
        }
        $seededPostFx = [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.42,
            'vignette_offset'   => 1.15,
            'vignette_blend'    => 'black',
        ];
        if (($vc['post_fx'] ?? null) === $seededPostFx) {
            unset($vc['post_fx']);
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $v2Description = 'Open-air garden exhibition. Hedges, trees, sky, and stone paths. Artworks on easels along a winding path.';
        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        if ((string) $row->description === $v3Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v2Description]);
        }

        // ── version (reversible under the same guard) ────────────────────
        if ($this->guardedEquals($row->version, '3.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INFINITE VOID DEEPENING — forensic-audit remediation for the void flagship
 * (slug: infinite-void). Screenshot-verified findings, not taste:
 *
 *  WHAT THE AUDIT FOUND
 *  ────────────────────
 *  1. THE RIG WAS LEFT BEHIND BY THE WHITE-CUBE POLISH: this venue still
 *     carried the pre-physical-units numbers (ambient 0.2 / spot 0.55 /
 *     fill 0.12 / exposure 0.55). The polish iteration proved that class of
 *     rig "rendered ~10× too dim" — here it meant the ARTWORK, the only
 *     bright thing an exhibition owns, rendered as a murky rectangle in its
 *     own hero frame. Retuned in physical-ish units while KEEPING the void
 *     mood: the darkness lives in the black background and the low ambient,
 *     never in the artworks' own light.
 *  2. THE DEFAULT POST STACK CONTRADICTED THE NAME: the venue declared no
 *     post_fx, so it inherited the global defaults — bloom ON (feeding on
 *     its white frames) and the Eskil vignette, which mixes screen edges
 *     toward (1 − darkness) = GREY. On a black scene that literally paints
 *     a grey ring around the "infinite" void — an enclosed tunnel. The
 *     declared post_fx turns bloom off and pushes the vignette target past
 *     black (darkness > 1), so the frame DISSOLVES into the dark instead.
 *  3. STANDING GLOW: artworks on a ring metres from the visitor sit beyond
 *     the proximity-light radius forever, so every piece rendered at the
 *     hard-coded 15% base of an already-small target. artwork_light_base
 *     (read by Lighting.js since this iteration) declares the fraction per
 *     venue — wall venues keep 0.15 untouched, the void declares 0.45 so
 *     every floating work carries its own island of light.
 *  4. PREVIEW ≠ PRODUCT (materials): material_config.floor_color 0x0a0a0a
 *     was silently DISCARDED whenever a floor texture existed — production
 *     (marble texture present) walked on a bright marble floor while the
 *     config (and every texture-less build) said near-black. The runtime
 *     honours declared colours as tints when material_config.texture_tint
 *     is true; the floor also gets a sharper specular response (roughness
 *     0.32, metalness 0.25) so each artwork's pool reads on the obsidian.
 *  5. WHITE FRAMES GLARED: default frame 'minimal' is pure white — floating
 *     white sticks in the dark, and the only thing the inherited bloom pass
 *     found to eat. The venue's default becomes the thin charcoal 'modern'
 *     frame (the same edge-definition reasoning as the White Cube polish),
 *     still visitor-overridable per gallery.
 *  6. DEPTH COMPOSITION: placement.depth_bands = 2 declares the venue's own
 *     presentation language — collections past 12 works compose in TWO
 *     depth rings (PlacementMath/RoomBuilder interpret the key generically;
 *     small shows keep the calm single ring). Walking now reveals parallax;
 *     the hang reads as a constellation in the void instead of a fence.
 *  7. ATMOSPHERE DECLARATIONS: void_depth_gradient = true (a near-black
 *     zenith gradient that makes the dark read as DISTANCE) joins the
 *     existing void_dust — both interpreted by the 'phenomena' pass; the
 *     dust body itself was rebuilt in JS (per-mote drift at all heights).
 *
 *  GUARDING (same contract as IT2/IT6/white-cube-polish): value rewrites
 *  fire ONLY while the stored value still equals the previously seeded one;
 *  structural adds (post_fx / placement / artwork_light_base / texture_tint)
 *  land only when absent. A super-admin's custom value always wins.
 *  Idempotent; down() reverses each change under the same exact-match guard.
 *
 *  The seeder is updated to the same end state (fresh-install baseline).
 *  The JS side ships in the bundle (Materials tint, Lighting base fraction,
 *  AssetLoader HDRI skip, circular roomBounds/far fix, dust rebuild, float
 *  obstacles, depth-band placement) and needs no migration.
 */
return new class extends Migration
{
    /**
     * Exact-match guard: strings strictly, numbers numerically (null never
     * matches — an absent value is not a seeded value).
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
        $row = DB::table('venue_templates')->where('slug', 'infinite-void')->first(['id', 'visual_config', 'material_config', 'default_settings', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        // Rig retune — exact-match guarded against the IT0/IT2 seeded values.
        $vcRewrites = [
            'ambient_intensity'     => ['from' => 0.2,  'to' => 0.3],
            'spot_intensity'        => ['from' => 0.55, 'to' => 1.3],
            'fill_intensity'        => ['from' => 0.12, 'to' => 0.2],
            'tone_mapping_exposure' => ['from' => 0.55, 'to' => 0.9],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Declared adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('artwork_light_base', $vc)) {
            // Standing-glow fraction for the pooled artwork lights (Lighting.js).
            $vc['artwork_light_base'] = 0.45;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            // Every artwork of a 12-piece hang carries its island of light at
            // once (pool raises to min(count, cap); tier floors apply). Past
            // the cap the visitor's proximity decides who is boosted — the
            // honest physics of 'islands of light' at scale.
            $vc['artwork_light_pool_cap'] = 12;
        }
        if (!array_key_exists('void_depth_gradient', $vc)) {
            // 'phenomena' pass ingredient: the zenith depth cue.
            $vc['void_depth_gradient'] = true;
        }
        if (!array_key_exists('post_fx', $vc)) {
            // Bloom off (nothing in a void should halo; white frames used to
            // be its only food). The vignette mixes edges toward
            // (1 − darkness): darkness 1.0 targets PURE BLACK with no
            // negatives. (darkness > 1 was tried first and measurably
            // BACKFIRED: the composer's HalfFloat buffers carry the negative
            // target through the chain and the ACES filmic curve — non-
            // monotonic for x < 0 — bounces it back to ~0.47 grey, i.e. a
            // stronger grey ring than the default it replaced. Zero is the
            // only mix target that survives tone mapping as black; the
            // wider offset 1.35 provides the falloff strength instead.)
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 1.0,
                'vignette_offset'   => 1.35,
            ];
        }
        if (!array_key_exists('placement', $vc)) {
            // §7 presentation language: depth-separated works. Interpreted
            // generically (PlacementMath + RoomBuilder); ≤ 12 works keep the
            // single ring, larger collections compose in two depth bands.
            $vc['placement'] = ['depth_bands' => 2];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config ──────────────────────────────────────────────
        $mc = json_decode((string) $row->material_config, true) ?: [];

        // Sharper specular response so the pooled artwork light reads on the
        // obsidian floor (was 0.4/0.6 — a metal with no environment renders
        // dead black and swallowed its own light pools).
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.4)) {
            $mc['floor_roughness'] = 0.32;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.6)) {
            $mc['floor_metalness'] = 0.25;
        }
        // Declared colours become authoritative over textures (Materials.js
        // tint path) — the production marble floor obeys 0x0a0a0a at last.
        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── default_settings ─────────────────────────────────────────────
        $ds = json_decode((string) $row->default_settings, true) ?: [];
        // White frames glare in a black venue (and fed the inherited bloom).
        // Charcoal 'modern' defines the canvas edge — same reasoning as the
        // White Cube polish pass. Visitor-overridable per gallery as ever.
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'minimal')) {
            $ds['frame_style'] = 'modern';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        // ── version ──────────────────────────────────────────────────────
        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'infinite-void')->first(['id', 'visual_config', 'material_config', 'default_settings', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'ambient_intensity'     => ['from' => 0.3,  'to' => 0.2],
            'spot_intensity'        => ['from' => 1.3,  'to' => 0.55],
            'fill_intensity'        => ['from' => 0.2,  'to' => 0.12],
            'tone_mapping_exposure' => ['from' => 0.9,  'to' => 0.55],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove the added keys — but only while they still carry exactly
        // what up() wrote (an admin's later edit is preserved).
        if (($vc['artwork_light_base'] ?? null) === 0.45) {
            unset($vc['artwork_light_base']);
        }
        if (($vc['artwork_light_pool_cap'] ?? null) === 12) {
            unset($vc['artwork_light_pool_cap']);
        }
        if (($vc['void_depth_gradient'] ?? null) === true) {
            unset($vc['void_depth_gradient']);
        }
        if (($vc['post_fx'] ?? null) === ['bloom' => false, 'vignette' => true, 'vignette_darkness' => 1.0, 'vignette_offset' => 0.92]) {
            unset($vc['post_fx']);
        }
        if (($vc['placement'] ?? null) === ['depth_bands' => 2]) {
            unset($vc['placement']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.32)) {
            $mc['floor_roughness'] = 0.4;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.25)) {
            $mc['floor_metalness'] = 0.6;
        }
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'modern')) {
            // Only restored when it still equals what up() wrote AND the
            // pre-pass value was the one this migration replaced.
            $ds['frame_style'] = 'minimal';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DARK MUSEUM DEEPENING — forensic-audit remediation for the museum venue
 * (slug: dark-museum). v1.0.0 → v2.0.0 ("the night wing").
 *
 * WHAT THE AUDIT FOUND (screenshot-verified, not taste):
 *   • The rig was the pre-polish profile (exposure 0.5, ambient 0.15,
 *     spot 0.55, fill 0.08) under r155+ physical light units — the venue
 *     rendered as a fog-crushed grey void; artworks were smudges.
 *   • fog 5→18 m dissolved a 10 m+ room into murk at mid-distance: the
 *     far wall was unreadable from the spawn. "Museum" needs architecture
 *     you can see; "dark" must be composed, not near-blind.
 *   • material_config declared wall_color 0x1a1a1a but NEVER the
 *     texture_tint flag — on every textured build the declared colour was
 *     silently replaced by 0xffffff (the Infinite Void preview/product
 *     split, unfixed here): desktop walls rendered White-Cube WHITE while
 *     low-end devices rendered the declared charcoal. The two tiers
 *     disagreed about the venue's own walls.
 *   • floor_color null → the bright marble preset (0xe8e8e8) — the FLOOR
 *     was the brightest surface in the "dark" museum, inverting the visual
 *     hierarchy away from the artwork.
 *   • post_fx was never declared, so the runtime default ran BLOOM ON in a
 *     dark venue of gold frames and bright canvases (halo class).
 *   • The dark venue kept the generic 0.15 artwork standing glow and the
 *     default 6-light pool — at its 15–50 capacity most of the hang sat in
 *     the dark (the "no artwork sits in the dark" exhibition rule).
 *   • The shared 0.15 hemisphere wash (not venue-declarable before this
 *     iteration) flattened the darkness hierarchy from above.
 *   • No placement curation: a venue whose entire concept is curation in
 *     darkness shipped the metronome default (3.5 m, no focal wall, no
 *     orientation pairing).
 *
 * THIS MIGRATION (DB side only — the JS identity ships in the bundle):
 *   visual_config : rig in physical-but-dark units, fog reach that covers
 *                   the room, post_fx restraint, artwork_light_base + pool
 *                   cap, env_intensity, hemisphere_intensity, placement
 *                   curation, ceiling/background/fog colours to the night
 *                   wing palette.
 *   material_config: texture_tint (THE fix), charcoal wall, dark stone
 *                   floor, floor_tile_meters 3.0.
 *   description   : verifiable copy for the rendered identity.
 *
 * GUARDING (same contract as the IT3/IT6/white-cube/loft migrations):
 * every rewrite fires ONLY while the stored value still equals the
 * previously seeded value (strings strictly, numbers numerically, explicit
 * nulls via array_key_exists). A super-admin's custom value is never
 * touched. Absent keys are added only when missing. Idempotent; down()
 * reverses each rewrite under the same exact-match guard.
 *
 * NOTE: paired with the seeder (fresh-install baseline). Version stays
 * seeder-owned (the loft migration contract).
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
            ->where('slug', 'dark-museum')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'ceiling_color'         => ['from' => '0x080808', 'to' => '0x0a0a0a'],
            'background_color'      => ['from' => '0x020202', 'to' => '0x050505'],
            'fog_color'             => ['from' => '0x020202', 'to' => '0x050505'],
            'fog_near'              => ['from' => 5,          'to' => 12],
            'fog_far'               => ['from' => 18,         'to' => 70],
            'ambient_color'         => ['from' => '0xfff4e6', 'to' => '0xffe8c8'],
            'ambient_intensity'     => ['from' => 0.15,       'to' => 3.2],
            'spot_intensity'        => ['from' => 0.55,       'to' => 1.9],
            'fill_intensity'        => ['from' => 0.08,       'to' => 0.5],
            'tone_mapping_exposure' => ['from' => 0.5,        'to' => 0.8],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_blend'    => 'black',
                'vignette_darkness' => 0.5,
                'vignette_offset'   => 1.15,
            ];
        } elseif (is_array($vc['post_fx']) && !array_key_exists('vignette_blend', $vc['post_fx'])) {
            // A saved post_fx without the blend key keeps every curated
            // value and adds ONLY the dark-scene blend fix.
            $vc['post_fx']['vignette_blend'] = 'black';
        }
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.32;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 14;
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0.14;
        }
        if (!array_key_exists('hemisphere_intensity', $vc)) {
            $vc['hemisphere_intensity'] = 0.04;
        }
        if (!array_key_exists('placement', $vc)) {
            $vc['placement'] = [
                'density'          => 'generous',
                'focal_wall'       => 'front',
                'pair_orientation' => true,
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config ──────────────────────────────────────────────
        $mc = json_decode((string) $row->material_config, true) ?: [];

        if ($this->guardedEquals($mc['wall_color'] ?? null, '0x1a1a1a')) {
            $mc['wall_color'] = '0x7a746c';
        }
        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.85)) {
            $mc['wall_roughness'] = 0.92;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.6)) {
            $mc['wall_normal_strength'] = 0.5;
        }
        // The v1 floor colour was an explicit null (→ bright preset marble).
        // Rewrite only while it is still null/absent.
        if (!array_key_exists('floor_color', $mc) || $mc['floor_color'] === null) {
            $mc['floor_color'] = '0x3a3835';
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.2)) {
            $mc['floor_metalness'] = 0.15;
        }
        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;   // THE fix — declared colours become
                                          // authoritative over the PBR sets
        }
        if (!array_key_exists('floor_tile_meters', $mc)) {
            $mc['floor_tile_meters'] = 3.0;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── description (customer-facing truth) ──────────────────────────
        $v1Description = 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.';
        $v2Description = 'A night-lit institution: charcoal galleries under a shadow-gap black ceiling, brass picture lights over every work, polished dark stone below. The architecture recedes; the artwork glows.';
        if ((string) $row->description === $v1Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v2Description]);
        }

        // ── version ──────────────────────────────────────────────────────
        // Same guarded stamp the infinite-void deepening uses: a row
        // deepened by THIS migration must not stay labelled 1.0.0.
        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'dark-museum')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'ceiling_color'         => ['from' => '0x0a0a0a', 'to' => '0x080808'],
            'background_color'      => ['from' => '0x050505', 'to' => '0x020202'],
            'fog_color'             => ['from' => '0x050505', 'to' => '0x020202'],
            'fog_near'              => ['from' => 12,         'to' => 5],
            'fog_far'               => ['from' => 70,         'to' => 18],
            'ambient_color'         => ['from' => '0xffe8c8', 'to' => '0xfff4e6'],
            'ambient_intensity'     => ['from' => 3.2,        'to' => 0.15],
            'spot_intensity'        => ['from' => 1.9,        'to' => 0.55],
            'fill_intensity'        => ['from' => 0.5,        'to' => 0.08],
            'tone_mapping_exposure' => ['from' => 0.8,        'to' => 0.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove the added keys only while they still equal what up() wrote.
        $seededPostFx = [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_blend'    => 'black',
            'vignette_darkness' => 0.5,
            'vignette_offset'   => 1.15,
        ];
        if (($vc['post_fx'] ?? null) === $seededPostFx) {
            unset($vc['post_fx']);
        } elseif (is_array($vc['post_fx'])
            && ($vc['post_fx']['vignette_blend'] ?? null) === 'black'
            && count($vc['post_fx']) === 1) {
            // The elseif-add path (curated post_fx + blend key only): remove
            // exactly the added key.
            unset($vc['post_fx']['vignette_blend']);
        }
        foreach ([
            'artwork_light_base'     => 0.32,
            'artwork_light_pool_cap' => 14,
            'env_intensity'          => 0.14,
            'hemisphere_intensity'   => 0.04,
        ] as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        if (($vc['placement'] ?? null) === [
            'density'          => 'generous',
            'focal_wall'       => 'front',
            'pair_orientation' => true,
        ]) {
            unset($vc['placement']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['wall_color'] ?? null, '0x7a746c')) {
            $mc['wall_color'] = '0x1a1a1a';
        }
        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.92)) {
            $mc['wall_roughness'] = 0.85;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.5)) {
            $mc['wall_normal_strength'] = 0.6;
        }
        if ($this->guardedEquals($mc['floor_color'] ?? null, '0x3a3835')) {
            $mc['floor_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.15)) {
            $mc['floor_metalness'] = 0.2;
        }
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        if (($mc['floor_tile_meters'] ?? null) === 3.0 || ($mc['floor_tile_meters'] ?? null) === 3) {
            unset($mc['floor_tile_meters']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $v1Description = 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.';
        $v2Description = 'A night-lit institution: charcoal galleries under a shadow-gap black ceiling, brass picture lights over every work, polished dark stone below. The architecture recedes; the artwork glows.';
        if ((string) $row->description === $v2Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v1Description]);
        }

        // ── version (reversible under the same guard) ────────────────────
        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};

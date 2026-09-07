<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * JAPANESE ZEN GALLERY DEEPENING — v1.0.0 → v2.0.0 ("The Quiet Procession")
 * for the venue row (slug: zen-gallery).
 *
 * WHAT THE v1.0.0 ROW WAS (the design brief's named failure mode): "a normal
 * gallery with Japanese decorations" — a pre-polish rig (exposure 0.55,
 * ambient 0.22, spot 0.45, fill 0.14: the measured ~10× too dim profile under
 * r155+ physical units), shoji/tokonoma/bench props at ABSOLUTE coordinates
 * (they broke on every layout change), a rotunda default the bay rhythm can
 * never serve, no post_fx declaration (runtime default bloom), no placement
 * curation, and an 'studio' environment declaration that gave a sealed room a
 * reflection source it never rendered.
 *
 * THIS MIGRATION (DB side only — the bay architecture ships in the JS bundle
 * as the generic structure_pass 'bays' interpreter):
 *   visual_config   : the procession rig (exposure 0.95, warm ambient 0.5,
 *                     spot 2.2, fill 1.3), warm-paper atmosphere (background
 *                     /fog 0xeee7d8, 18→60), ceiling 0xe9e2d0 at 3.6 m,
 *                     artwork legibility (base 0.25, pool 10), hemisphere
 *                     0.1, environment 'none' + env_intensity 0 (declared
 *                     absence — no sky can leak into the venue), sumi-ink
 *                     frame_override, structure_pass 'bays' + the bays
 *                     proportion block, placement curation (generous /
 *                     focal front / pairing), post-fx restraint.
 *   material_config : texture_tint authority + warm limewash wall 0xe6dfcf,
 *                     pale cedar floor 0xa98d64 at tatami tile scale (1.8).
 *   default_settings: plaster/wood finishes, coherent sumi-ink frame start,
 *                     square layout default.
 *   supported_layouts: square / corridor / l-shape (rotunda dropped — the
 *                     procession is linear; layoutForGallery clamps legacy
 *                     rotunda rows to the venue default automatically).
 *   description     : verifiable copy for the rendered identity.
 *
 * GUARDING (same contract as the white-cube / loft / museum migrations):
 * every rewrite fires ONLY while the stored value still equals the
 * previously seeded v1.0.0 value (strings strictly, numbers numerically).
 * The v1 structure descriptor array (the props) is removed only under the
 * three-way v1 baseline guard (version 1.0.0 + structure_pass 'rooms' + v1
 * description) — a hand-tuned row keeps its descriptors. Absent keys are
 * added only when missing. Idempotent; down() reverses each rewrite under
 * the same exact-match guard.
 *
 * NOTE: paired with the seeder (fresh-install baseline). Version stamps
 * like the museum deepening (guarded 1.0.0 → 2.0.0).
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
        if (is_bool($from)) {
            return is_bool($current) && $current === $from;
        }
        return is_numeric($current) && (float) $current === (float) $from;
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'zen-gallery')
            ->first(['id', 'visual_config', 'material_config', 'default_settings', 'supported_layouts', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'wall_height'            => ['from' => 3.2,        'to' => 3.6],
            'wall_depth'             => ['from' => 0.15,       'to' => 0.3],
            'ceiling_color'          => ['from' => '0x1e1c14', 'to' => '0xe9e2d0'],
            'ceiling_height'         => ['from' => 3.2,        'to' => 3.6],
            'background_color'       => ['from' => '0x1a1710', 'to' => '0xeee7d8'],
            'fog_color'              => ['from' => '0x1a1710', 'to' => '0xeee7d8'],
            'fog_near'               => ['from' => 12,         'to' => 18],
            'fog_far'                => ['from' => 40,         'to' => 60],
            'ambient_color'          => ['from' => '0xffe8c2', 'to' => '0xfff2dd'],
            'ambient_intensity'      => ['from' => 0.22,       'to' => 0.5],
            'spot_intensity'         => ['from' => 0.45,       'to' => 2.2],
            'fill_intensity'         => ['from' => 0.14,       'to' => 1.3],
            'tone_mapping_exposure'  => ['from' => 0.55,       'to' => 0.95],
            // v1 declared null (no override) — v2 declares sumi ink.
            'environment'            => ['from' => 'studio',   'to' => 'none'],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.25;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 10;
        }
        if (!array_key_exists('hemisphere_intensity', $vc)) {
            $vc['hemisphere_intensity'] = 0.1;
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0;
        }
        if (!array_key_exists('bays', $vc)) {
            $vc['bays'] = [
                'fin_width'         => 0.16,
                'fin_depth'         => 0.14,
                'fin_top'           => 3.12,
                'header_height'     => 0.20,
                'recess_lift'       => 0.012,
                'step_height'       => 0.08,
                'step_depth'        => 0.36,
                'clerestory_gap'    => 0.05,
                'clerestory_height' => 0.24,
            ];
        }
        if (!array_key_exists('placement', $vc)) {
            $vc['placement'] = [
                'density'          => 'generous',
                'focal_wall'       => 'front',
                'pair_orientation' => true,
            ];
        }
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 0.3,
                'vignette_offset'   => 1.1,
            ];
        }

        // frame_override: v1 stored NULL (absent override). Add the sumi-ink
        // declaration only while it is still null/absent.
        if (!array_key_exists('frame_override', $vc) || $vc['frame_override'] === null) {
            $vc['frame_override'] = 'black';
        }

        // structure_pass 'rooms' → 'bays' + the v1 props removed. The
        // descriptor array goes ONLY under the three-way baseline guard —
        // a hand-tuned row keeps its descriptors (and its pass).
        $v1Description = 'A quiet, focused space: shoji screens, a tokonoma alcove and warm wood, tuned for close, calm looking.';
        if (($vc['structure_pass'] ?? null) === 'rooms'
            && $this->guardedEquals($row->version, '1.0.0')
            && (string) $row->description === $v1Description) {
            $vc['structure_pass'] = 'bays';
            unset($vc['structure']);
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config ──────────────────────────────────────────────
        $mc = json_decode((string) $row->material_config, true) ?: [];

        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.7)) {
            $mc['wall_roughness'] = 0.95;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.5)) {
            $mc['wall_normal_strength'] = 0.35;
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.7)) {
            $mc['floor_roughness'] = 0.62;
        }
        if ($this->guardedEquals($mc['floor_normal_strength'] ?? null, 0.6)) {
            $mc['floor_normal_strength'] = 0.5;
        }
        // The v1 wall/floor colours were explicit nulls (→ preset colours).
        if (!array_key_exists('wall_color', $mc) || $mc['wall_color'] === null) {
            $mc['wall_color'] = '0xe6dfcf';
        }
        if (!array_key_exists('floor_color', $mc) || $mc['floor_color'] === null) {
            $mc['floor_color'] = '0xa98d64';
        }
        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;   // THE fix — declared colours become
                                          // authoritative over the PBR sets
        }
        if (!array_key_exists('floor_tile_meters', $mc)) {
            $mc['floor_tile_meters'] = 1.8;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── default_settings (the five exhibition starting values) ──────
        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if ($this->guardedEquals($ds['wall_texture'] ?? null, 'wood')) {
            $ds['wall_texture'] = 'plaster';
        }
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'minimal')) {
            $ds['frame_style'] = 'black';
        }
        if ($this->guardedEquals($ds['room_layout'] ?? null, 'rotunda')) {
            $ds['room_layout'] = 'square';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        // ── supported_layouts: the procession is linear ──────────────────
        $layouts = json_decode((string) $row->supported_layouts, true);
        if (is_array($layouts)
            && $layouts === ['square', 'rotunda', 'l-shape']) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['supported_layouts' => json_encode(['square', 'corridor', 'l-shape'])]);
        }

        // ── description (customer-facing truth) ─────────────────────────
        $v2Description = 'A low, calm room of framed bays: dark timber fins divide warm plaster walls, each work centred in its own bay beneath a glowing paper band, over a pale cedar floor. Made for close, quiet looking.';
        if ((string) $row->description === $v1Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v2Description]);
        }

        // ── version ──────────────────────────────────────────────────────
        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'zen-gallery')
            ->first(['id', 'visual_config', 'material_config', 'default_settings', 'supported_layouts', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'wall_height'            => ['from' => 3.6,        'to' => 3.2],
            'wall_depth'             => ['from' => 0.3,        'to' => 0.15],
            'ceiling_color'          => ['from' => '0xe9e2d0', 'to' => '0x1e1c14'],
            'ceiling_height'         => ['from' => 3.6,        'to' => 3.2],
            'background_color'       => ['from' => '0xeee7d8', 'to' => '0x1a1710'],
            'fog_color'              => ['from' => '0xeee7d8', 'to' => '0x1a1710'],
            'fog_near'               => ['from' => 18,         'to' => 12],
            'fog_far'                => ['from' => 60,         'to' => 40],
            'ambient_color'          => ['from' => '0xfff2dd', 'to' => '0xffe8c2'],
            'ambient_intensity'      => ['from' => 0.5,        'to' => 0.22],
            'spot_intensity'         => ['from' => 2.2,        'to' => 0.45],
            'fill_intensity'         => ['from' => 1.3,        'to' => 0.14],
            'tone_mapping_exposure'  => ['from' => 0.95,       'to' => 0.55],
            'environment'            => ['from' => 'none',     'to' => 'studio'],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove the added keys only while they still equal what up() wrote.
        foreach ([
            'artwork_light_base'     => 0.25,
            'artwork_light_pool_cap' => 10,
            'hemisphere_intensity'   => 0.1,
            'env_intensity'          => 0,
        ] as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        if (($vc['bays'] ?? null) === [
            'fin_width'         => 0.16,
            'fin_depth'         => 0.14,
            'fin_top'           => 3.12,
            'header_height'     => 0.20,
            'recess_lift'       => 0.012,
            'step_height'       => 0.08,
            'step_depth'        => 0.36,
            'clerestory_gap'    => 0.05,
            'clerestory_height' => 0.24,
        ]) {
            unset($vc['bays']);
        }
        if (($vc['placement'] ?? null) === [
            'density'          => 'generous',
            'focal_wall'       => 'front',
            'pair_orientation' => true,
        ]) {
            unset($vc['placement']);
        }
        if (($vc['post_fx'] ?? null) === [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.3,
            'vignette_offset'   => 1.1,
        ]) {
            unset($vc['post_fx']);
        }
        if (($vc['frame_override'] ?? null) === 'black') {
            $vc['frame_override'] = null;
        }
        if (($vc['structure_pass'] ?? null) === 'bays') {
            $vc['structure_pass'] = 'rooms';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.95)) {
            $mc['wall_roughness'] = 0.7;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.35)) {
            $mc['wall_normal_strength'] = 0.5;
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.62)) {
            $mc['floor_roughness'] = 0.7;
        }
        if ($this->guardedEquals($mc['floor_normal_strength'] ?? null, 0.5)) {
            $mc['floor_normal_strength'] = 0.6;
        }
        if ($this->guardedEquals($mc['wall_color'] ?? null, '0xe6dfcf')) {
            $mc['wall_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_color'] ?? null, '0xa98d64')) {
            $mc['floor_color'] = null;
        }
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        if (($mc['floor_tile_meters'] ?? null) === 1.8 || ($mc['floor_tile_meters'] ?? null) === 1) {
            unset($mc['floor_tile_meters']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if ($this->guardedEquals($ds['wall_texture'] ?? null, 'plaster')) {
            $ds['wall_texture'] = 'wood';
        }
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'black')) {
            $ds['frame_style'] = 'minimal';
        }
        if ($this->guardedEquals($ds['room_layout'] ?? null, 'square')) {
            $ds['room_layout'] = 'rotunda';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        $layouts = json_decode((string) $row->supported_layouts, true);
        if (is_array($layouts) && $layouts === ['square', 'corridor', 'l-shape']) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['supported_layouts' => json_encode(['square', 'rotunda', 'l-shape'])]);
        }

        $v1Description = 'A quiet, focused space: shoji screens, a tokonoma alcove and warm wood, tuned for close, calm looking.';
        $v2Description = 'A low, calm room of framed bays: dark timber fins divide warm plaster walls, each work centred in its own bay beneath a glowing paper band, over a pale cedar floor. Made for close, quiet looking.';
        if ((string) $row->description === $v2Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v1Description]);
        }

        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};

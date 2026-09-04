<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WHITE CUBE POLISH — forensic-audit remediation for the flagship free venue
 * (slug: white-cube).
 *
 * WHAT THE AUDIT FOUND (screenshot-verified, not taste):
 *   • fog_color 0x0f0f0f (void-black) at 10→30 m sooted every far wall — a
 *     "white cube" whose atmosphere dissolved into soot. The fog now
 *     dissolves toward gallery white (0xf2f1ee, 16→60 m) and large rooms
 *     read as bright infinity instead of a dark tunnel.
 *   • tone_mapping_exposure 0.5 halved an already-weak rig: the point-light
 *     intensities predate three's physical light units (r155+) and rendered
 *     ~10× too dim — artworks sat near-black on grey walls. The rig is now
 *     declared in physical units (ambient 0.55, spot 3.2, fill 2.6,
 *     exposure 1.05) so artworks are the brightest surface in the room.
 *   • fill_intensity was DEAD CONFIG — stored in this row, never read by the
 *     runtime (the ceiling grid read the lighting preset instead). The
 *     runtime now honours it (Lighting.venueFillIntensity), so the declared
 *     2.6 is real.
 *   • Bloom halos contradicted the venue's calm identity — post_fx now
 *     declares bloom OFF and a softened vignette (interpreted generically by
 *     PostProcessing.applyVenueConfig; zero slug knowledge in JS).
 *   • The floor preset (wet-cement grey 0x6b6b6b @ roughness 0.9) swallowed
 *     light — declared as sealed polished concrete (0x9c9c98 @ 0.55).
 *   • default frame 'minimal' (white-on-white) dissolved the hang's edges —
 *     default is now the thin charcoal 'modern' frame (still
 *     visitor-overridable per gallery).
 *
 * GUARDING (same contract as the IT3/IT6 migrations): every rewrite fires
 * ONLY while the stored value still equals the previously seeded value.
 * A super-admin's custom value is never touched. Absent keys (post_fx) are
 * added only when missing. Idempotent; down() reverses each rewrite under
 * the same exact-match guard.
 *
 * NOTE: this migration is paired with the seeder (fresh-install baseline).
 * The JS side of the fix (buried respect-pass trim, layout parity, mipmap
 * guard, exposure clobber, placement centring) ships in the bundle and
 * needs no migration.
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
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        // Value rewrites — exact-match guarded against the IT0..IT6 seeded values.
        // Strings compare strictly; numbers compare numerically. (A (float)
        // cast on the hex STRINGS would be wrong twice over: PHP casts every
        // '0x…' string to 0.0, so distinct colours would compare equal and
        // an admin's '0x000000' would match the guard.)
        $vcRewrites = [
            'background_color'      => ['from' => '0x0f0f0f', 'to' => '0xf2f1ee'],
            'fog_color'             => ['from' => '0x0f0f0f', 'to' => '0xf2f1ee'],
            'fog_near'              => ['from' => 10,         'to' => 16],
            'fog_far'               => ['from' => 30,         'to' => 60],
            'ambient_intensity'     => ['from' => 0.2,        'to' => 0.55],
            'spot_intensity'        => ['from' => 0.45,       'to' => 3.2],
            'fill_intensity'        => ['from' => 0.12,       'to' => 2.6],
            'tone_mapping_exposure' => ['from' => 0.5,        'to' => 1.05],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared post_fx wins).
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 0.28,
                'vignette_offset'   => 1.05,
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config ──────────────────────────────────────────────
        $mc = json_decode((string) $row->material_config, true) ?: [];
        if (($mc['floor_color'] ?? null) === null) {
            $mc['floor_color'] = '0x9c9c98';
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.7)) {
            $mc['floor_roughness'] = 0.55;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── default_settings ─────────────────────────────────────────────
        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['frame_style'] ?? null) === 'minimal') {
            $ds['frame_style'] = 'modern';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'background_color'      => ['from' => '0xf2f1ee', 'to' => '0x0f0f0f'],
            'fog_color'             => ['from' => '0xf2f1ee', 'to' => '0x0f0f0f'],
            'fog_near'              => ['from' => 16,         'to' => 10],
            'fog_far'               => ['from' => 60,         'to' => 30],
            'ambient_intensity'     => ['from' => 0.55,       'to' => 0.2],
            'spot_intensity'        => ['from' => 3.2,        'to' => 0.45],
            'fill_intensity'        => ['from' => 2.6,        'to' => 0.12],
            'tone_mapping_exposure' => ['from' => 1.05,       'to' => 0.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove post_fx only while it still equals what up() wrote.
        if (($vc['post_fx'] ?? null) === [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.28,
            'vignette_offset'   => 1.05,
        ]) {
            unset($vc['post_fx']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if (($mc['floor_color'] ?? null) === '0x9c9c98') {
            $mc['floor_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.55)) {
            $mc['floor_roughness'] = 0.7;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['frame_style'] ?? null) === 'modern') {
            $ds['frame_style'] = 'minimal';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRYSTAL CATHEDRAL — deploy-review refinement pass (v2.0.0 → 2.1.0).
 * (2026-09-08. Predecessor: 2026_09_07_000001 "The Luminous Arcade".)
 *
 * WHY
 * ---
 * The redesigned arcade deployed and the owner's production screenshot
 * exposed two defects the sandbox evidence (low-tier only, see below) could
 * never show:
 *
 * 1. THE GREY VEIL (the "haze"). The arcade's post_fx declared
 *    vignette true / darkness 0.62 but NOT vignette_blend — so the
 *    ExospaceVignetteShader kept its legacy GREY blend target of
 *    (1 − 0.62) = 0.38. mix(scene, 0.38 grey, dot(uv, uv)) then LIFTED every
 *    frame edge toward grey: measured ~130-142 RGB in the corners of a
 *    venue whose identity is deep blue-black. The Dark Museum audit hit the
 *    same shader and fixed it with vignette_blend 'black'
 *    (2026_09_06_000002); the cathedral row was written without the key.
 *    The migration comment even said "black-blend vignette 0.62" — the
 *    implementation never matched its own documented intent.
 *
 * 2. THE BLACK MID-BAND. With the veil removed the art-bay wall (0→4.6 m,
 *    0x131a26) has nothing reaching it — the oculus spot is a narrow centre
 *    cone, the artwork pool lights are local — so the "framed bays of
 *    stone" the copy promises rendered as one black void and the arcade
 *    piers read as floating unattached. hemisphere_intensity 0.22 → 0.30
 *    lifts the vertical sky-above gradient so the wall band and pier bases
 *    catch the cool sky tone. (The bay framing itself — dressed-stone trim
 *    — is a VenueDecorator presentation derivation from the DECLARED
 *    wall_color, not a config key; it ships in the same iteration.)
 *
 * EVIDENCE NOTE: the 2026_09_07 visual QA captured LOW-tier screenshots
 * only (gloss floor, Lambert glass — no Reflector, no transmission), so
 * neither the high-tier reflector doubling nor this vignette veil was ever
 * seen before deploy. The harness now pins the high tier for cathedral
 * captures (250x clock stretch defeats the SwiftShader FPS-benchmark
 * downgrade) and the venue QA asserts both fixes.
 *
 * SAFETY (production data protection — same pattern as every venue pass)
 * ---------------------------------------------------------------------
 *   • hemisphere_intensity swaps ONLY while still equal to the seeded
 *     0.22 — a super-admin retune survives.
 *   • vignette_blend is a UNION add inside post_fx (absent key only).
 *   • Version swap exact-match guarded.
 *   • Portable PHP read-modify-write; idempotent; down() restores the exact
 *     previous state under the same guards.
 *   • The venue_config cache re-keys from the row contents, so the fix is
 *     live on the next render after migrate — no manual cache clear.
 */
return new class extends Migration
{
    private const SLUG = 'crystal-cathedral';

    private const OLD_VERSION = '2.0.0';
    private const NEW_VERSION = '2.1.0';

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // 1. Guarded hemisphere lift (seeded value only — admin edits win).
        if (($visual['hemisphere_intensity'] ?? null) === 0.22) {
            $visual['hemisphere_intensity'] = 0.30;
        }

        // 2. Union-add the black vignette blend inside post_fx (the Dark
        //    Museum audit's fix, applied to the second dark venue). Absent
        //    key only — an operator who chose 'grey' keeps it.
        if (is_array($visual['post_fx'] ?? null) && !array_key_exists('vignette_blend', $visual['post_fx'])) {
            $visual['post_fx']['vignette_blend'] = 'black';
        }

        $update = ['visual_config' => json_encode($visual)];

        // 3. Guarded version bump.
        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Reverse exactly what up() wrote, under the same guards.
        if (($visual['hemisphere_intensity'] ?? null) === 0.30) {
            $visual['hemisphere_intensity'] = 0.22;
        }

        if (is_array($visual['post_fx'] ?? null) && ($visual['post_fx']['vignette_blend'] ?? null) === 'black') {
            unset($visual['post_fx']['vignette_blend']);
        }

        $update = ['visual_config' => json_encode($visual)];

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }
};

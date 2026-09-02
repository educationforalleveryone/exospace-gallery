<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 2 "Phenomena" (roadmap P1.2): the Void family identity pass.
 *
 * WHAT IT SHIPS (data side)
 * -------------------------
 * For the four void venues (infinite-void, crystal-cathedral, nebula-drift,
 * mirror-lake):
 *
 *   1. Declared-identity keys merged into visual_config:
 *        placement_mode    = 'float'        → artworks hover (§10.5) — the
 *                                             "floating artworks" promise made
 *                                             literally true on every tier.
 *        structure_pass    = 'phenomena'    → the new structure bodies render
 *                                             (colonnade / fog-exempt stars /
 *                                             reflector path). This key is the
 *                                             PER-VENUE ROLLBACK SWITCH: remove
 *                                             it from one venue's JSON and that
 *                                             venue reverts to its pre-pass
 *                                             render, live, no deploy.
 *        floor_edge_fade   = true           → infinite-void only: the ground
 *                                             disc dissolves into the void (§4.2).
 *        glass_material    = 'transmission' → cathedral only: true glass on
 *                                             high tier, DESIGNED cheap-glass
 *                                             fallback on mobile/low-end — the
 *                                             null-glass defect is unreachable.
 *        floor_reflection  = 'planar'       → mirror-lake only: real planar
 *                                             reflection on high tier, designed
 *                                             dark-gloss mood on mobile/low-end.
 *        env_intensity     = 0 / 0.05 / 0.15→ silences the accidental HDRI
 *                                             horizon glow inside voids (§4.7).
 *
 *   2. Copy re-tightened (the roadmap's P0.1 loop: words → render → words):
 *      the Iteration 0 wording described easels because easels were the truth.
 *      Float placement + the reflector now ship, so the descriptions return to
 *      promising the real phenomena. Each rewrite is GUARDED: it applies only
 *      when the row still carries the exact Iteration 0 text — a super-admin
 *      who customized a description keeps theirs.
 *
 * NAME DECISION GATE (§4.11): resolved — the reflector ships, so the venue
 * keeps the name "Mirror Lake". No rename in this migration.
 *
 * SAFETY (production data protection)
 * -----------------------------------
 * visual_config keys are merged with array UNION: a key the admin has already
 * set is NEVER overwritten — only ABSENT keys are added. Descriptions are
 * guarded by exact-match on the Iteration 0 text. The migration is portable
 * (PHP read-modify-write, no MySQL-only JSON functions) so it runs identically
 * on MySQL/MariaDB and in the sqlite test environment. Idempotent: re-running
 * adds nothing (keys present) and rewrites nothing (guards miss).
 *
 * Rollback: down() removes exactly the keys added (only where still equal to
 * what up() wrote) and restores the Iteration 0 descriptions (same guard).
 */
return new class extends Migration
{
    private const OLD_DESCRIPTIONS = [
        'infinite-void'     => 'A vast dark space with slowly drifting dust. Artworks presented in the round on easels — no walls, no ceiling.',
        'crystal-cathedral' => 'Crystalline forms drift through a deep blue void, lit by shifting colour. An ethereal, open exhibition space.',
        'nebula-drift'      => 'Drift through a cosmic cloud of stars and purple nebula. For digital art and otherworldly exhibitions.',
        'mirror-lake'       => 'A still, dark lake floor beneath soft mist and moonlight. Quiet, spacious, meditative.',
    ];

    private const NEW_DESCRIPTIONS = [
        'infinite-void'     => 'Weightless artworks float in an endless dark, dust drifting slowly around them. No walls, no ceiling, no horizon.',
        'crystal-cathedral' => 'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.',
        'nebula-drift'      => 'Artworks drift through a cosmic cloud — distant stars and a purple nebula with quiet depth between them. For digital art and otherworldly exhibitions.',
        'mirror-lake'       => 'A still, dark lake reflects the floating artworks and the moon. Mist drifts low. Quiet, spacious, meditative.',
    ];

    /**
     * Per-venue visual_config keys to ADD (absent keys only — admin edits win).
     */
    private function identityKeys(): array
    {
        return [
            'infinite-void' => [
                'placement_mode'  => 'float',
                'floor_edge_fade' => true,
                'env_intensity'   => 0,
                'structure_pass'  => 'phenomena',
            ],
            'crystal-cathedral' => [
                'placement_mode' => 'float',
                'glass_material' => 'transmission',
                'colonnade_tint' => '0xdfeaff',
                'structure_pass' => 'phenomena',
            ],
            'nebula-drift' => [
                'placement_mode' => 'float',
                'env_intensity'  => 0.05,
                'structure_pass' => 'phenomena',
            ],
            'mirror-lake' => [
                'placement_mode'  => 'float',
                'floor_reflection' => 'planar',
                'env_intensity'   => 0.15,
                'structure_pass'  => 'phenomena',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->identityKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config', 'description']);
            if (!$row) {
                continue; // venue removed by the operator — respect that
            }

            // 1. Union-merge declared identity keys (existing keys win).
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $merged   = $existing + $keys;
            if ($merged !== $existing) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($merged)]);
            }

            // 2. Guarded copy re-tightening (admin-customized text is kept).
            $old = self::OLD_DESCRIPTIONS[$slug];
            $new = self::NEW_DESCRIPTIONS[$slug];
            if ($row->description === $old) {
                DB::table('venue_templates')->where('id', $row->id)->update(['description' => $new]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->identityKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config', 'description']);
            if (!$row) {
                continue;
            }

            // Remove exactly the keys up() added — but only while they still
            // carry the value up() wrote (an admin's later edit is preserved).
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                }
            }
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['visual_config' => json_encode($existing)]);

            // Restore the Iteration 0 description (guarded the same way).
            $old = self::OLD_DESCRIPTIONS[$slug];
            $new = self::NEW_DESCRIPTIONS[$slug];
            if ($row->description === $new) {
                DB::table('venue_templates')->where('id', $row->id)->update(['description' => $old]);
            }
        }
    }
};

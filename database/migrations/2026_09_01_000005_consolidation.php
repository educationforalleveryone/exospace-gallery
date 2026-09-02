<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 6 "Consolidation" (roadmap P2.2 + P2.3): the data side of the
 * slug-free runtime.
 *
 * WHAT IT SHIPS
 * -------------
 * The JS no longer contains ANY slug-keyed venue knowledge (DoD rule #7 —
 * `legacyVenueSwitch`, `venueTints`, `venueFrameOverride`, the ceiling
 * colour chains and the `CIRCULAR_VENUES`/`OPEN_AIR_VENUES` sets are all
 * deleted from resources/js/gallery). Every one of those strata is replaced
 * by a DECLARED config key, and the structure dispatch is selected by
 * `visual_config.structure_pass`. This migration writes the exact equivalent
 * values into every seeded venue's JSON so renders are BYTE-IDENTICAL
 * pre/post migration (the equivalence table below is the IT6 promise test):
 *
 *   venue            keys written                                        replaces
 *   ───────────────  ──────────────────────────────────────────────────  ─────────────────────────────
 *   white-cube       structure_pass: 'rooms' → 'cube' (guarded update)   slug+rooms gate on respect pass
 *   infinite-void    open_air, layout_shape:circular, void_dust          OPEN_AIR/CIRCULAR sets, slug dust
 *   industrial-loft  ceiling_color:0x1a1a18, ceiling_beams, pass:loft    ceiling chain, beam branch, slug
 *   dark-museum      ceiling_color:0x080808, pass:museum                 ceiling chain, slug dispatch
 *   zen-gallery      ceiling_color:0x1e1c14                              ceiling chain
 *   crystal-cathedral open_air, layout_shape:circular, void_colonnade    sets, cathedral slug gate
 *   nebula-drift     open_air, layout_shape:circular, void_starfield     sets, nebula slug branch
 *   luxury-penthouse ceiling_color:0x080808                              ceiling chain
 *   cyber-gallery    ceiling_color:0x04081a, ceiling_neon                ceiling chain, neon slug branch
 *   sculpture-garden open_air, layout_shape:circular, pass:garden        sets, garden slug branch
 *   mirror-lake      open_air, layout_shape:circular, void_lake          sets, lake slug branch
 *
 * (venueTints / venueFrameOverride need no data: every seeded venue already
 * declared ambient_color / frame_override, which the JS preferred anyway —
 * those maps were config-shadowed dead code for the whole catalog.)
 *
 * P2.3 curation (density / pairing / focal wall) ships as OPT-IN keys under
 * `visual_config.placement` — deliberately NOT written here: "default
 * galleries unchanged" is this iteration's own contract. Declaring a
 * placement block is the switch (§11.3 rule 2); no feature flag.
 *
 * SAFETY (production data protection) — same pattern as Iterations 2/3/5:
 * added keys merge with array UNION (admin-set keys are NEVER overwritten;
 * only absent keys are added). The single VALUE update (white-cube's
 * structure_pass) is guarded by exact match on the value IT3 wrote ('rooms')
 * so a super-admin's later edit is never touched. Portable PHP
 * read-modify-write (no MySQL-only JSON functions) — runs identically on
 * MySQL/MariaDB and sqlite. Idempotent: re-running adds nothing (keys
 * present) and rewrites nothing (guard misses).
 *
 * ROLLBACK: down() removes exactly the keys up() added (only while still
 * equal to what up() wrote) and restores white-cube's structure_pass to
 * 'rooms' (only while still 'cube'). Migrate:rollback runs newest-first, so
 * the chain back through IT3/IT2 stays coherent. Per-venue, no-deploy
 * runtime rollback: remove the key(s) from one venue's JSON and that venue
 * reverts live (and the IT5 snapshot system can restore pre-IT6 JSON whole).
 */
return new class extends Migration
{
    /**
     * visual_config keys to ADD per venue (absent keys only — admin edits win).
     * Values are byte-equivalent to the pre-IT6 JS chains they replace.
     */
    private function consolidationKeys(): array
    {
        return [
            'infinite-void' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_dust'     => true,
            ],
            'industrial-loft' => [
                'ceiling_color' => '0x1a1a18',
                'ceiling_beams' => true,
                'structure_pass' => 'loft',
            ],
            'dark-museum' => [
                'ceiling_color' => '0x080808',
                'structure_pass' => 'museum',
            ],
            'zen-gallery' => [
                'ceiling_color' => '0x1e1c14',
            ],
            'crystal-cathedral' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_colonnade' => true,
            ],
            'nebula-drift' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_starfield' => true,
            ],
            'luxury-penthouse' => [
                'ceiling_color' => '0x080808',
            ],
            'cyber-gallery' => [
                'ceiling_color' => '0x04081a',
                'ceiling_neon'  => true,
            ],
            'sculpture-garden' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'structure_pass' => 'garden',
            ],
            'mirror-lake' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_lake'     => true,
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->consolidationKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue; // venue removed by the operator — respect that
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $merged   = $existing + $keys;
            if ($merged !== $existing) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($merged)]);
            }
        }

        // white-cube: re-own the key IT3 shipped — 'rooms' → 'cube' (the
        // respect pass is its own interpreter selector now). Exact-match
        // guard: an admin's custom pass value is never touched.
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config']);
        if ($row) {
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            if (($existing['structure_pass'] ?? null) === 'rooms') {
                $existing['structure_pass'] = 'cube';
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }
    }

    public function down(): void
    {
        // Restore white-cube first (reverse of up's guarded update).
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config']);
        if ($row) {
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            if (($existing['structure_pass'] ?? null) === 'cube') {
                $existing['structure_pass'] = 'rooms';
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }

        // Remove the added keys — but ONLY while each still equals the value
        // up() wrote (a key the admin later edited stays).
        foreach ($this->consolidationKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $changed  = false;
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LUXURY PENTHOUSE — "The Media Wall" living-room pass (3.0.0 → 3.1.0).
 * (2026-09-09. Predecessor: 2026_09_09_000008 "Convergence" — the drifted-row
 * repair this migration assumes as its baseline.)
 *
 * WHY (owner feedback on the deployed v3.0.0 floor, screenshot-verified)
 * ---------------------------------------------------------------------
 * Four forensic reads on the live render, each mapped to a named object:
 *
 *  M1  THE LOUNGE READS AS A DEAD BLACK MASS. Walking wing B toward the
 *      glass corner, the sofa group (fabric_warm, unlit from the walk
 *      side) reads as an unpowered slab — the owner literally asked for
 *      "an LCD there, there is a sofa nearby". This pass gives the lounge
 *      exactly that: a freestanding media console in the solid-wall/glass
 *      corner (basalt slab + bronze-dark bezel), 45° into the room so the
 *      arrival sightline AND the sofa both own it. The SCREEN itself is
 *      drawn by the viewer (visual_config.media_wall → VenueDecorator
 *      buildMediaWall): a canvas "Now Showing" idle display — gallery
 *      wordmark, featured artwork thumbnail, work count — because a
 *      template row cannot know the gallery title it will serve.
 *  M2  THE ART WALL'S STATEMENT WORK WAS INVISIBLE. wall_left_high hangs
 *      at 2.6 m over a walnut panel with zero dedicated light — black-
 *      background works vanish into the walnut. Adds a bronze picture-
 *      light bar (emissive, proud of the panel above the hang centre) and
 *      an anchored warm point fixture washing the hang.
 *  M3  THE FLOOR LAMP READ AS A FLOATING PANEL. Through the east glass
 *      the 3.5 cm pole disappeared against the dusk skyline and the
 *      0.8-strength shade read as a blank floating rectangle ("looks
 *      empty"). The lamp moves into the north-east lounge corner (out of
 *      the mid-glass sightline, tucked against the mullion cluster), the
 *      pole thickens to 5.5 cm, the shade strengthens to 1.35.
 *  M4  SMALL CRAFT DEBTS. (a) The axis knot floated 0.10 m above its
 *      plinth (authored y 1.42 vs plinth top 1.10) — "one bronze knot ON
 *      basalt" now means on: y 1.32 rests it. (b) The gallery bench base
 *      was dark_trim (#111214) — in the unlit spawn corner it read as a
 *      hole in the floor; the base joins the walnut family.
 *
 * SAFETY (the 000008 lesson, applied)
 * -----------------------------------
 *  • Every mutation is guarded PER DESCRIPTOR by the semantic comparator
 *    (numbers by value, maps key-order-free, lists strict): the swap
 *    fires ONLY while that descriptor still equals the v3.0.0 chain
 *    body. An admin-edited descriptor is logged and left alone.
 *  • Additions (media console/bezel, picture bar, fixtures, media_wall
 *    key) fire only when their ids/keys are ABSENT — replay-safe.
 *  • down() restores v3.0.0 exactly (guarded the same way), so the
 *    000008→000007 rollback chain stays coherent.
 *  • Loud echo logging under [luxury-penthouse-media-wall] — the deploy
 *    log is the audit trail (no reseeding, no cache clears).
 *
 * The viewer half (buildMediaWall) ships in the same release; a venue
 * without the JS simply renders the console + bezel as furniture.
 */
return new class extends Migration
{
    private const SLUG = 'luxury-penthouse';

    /** The v3.0.0 description this pass upgrades from (exact-match guard). */
    private const V3_DESCRIPTION = 'A private collector\'s floor in two volumes — a low, coved gallery procession that lifts at a lit seam into a double-height living room glazed to the dusk city on two faces, the largest work living above the stone fireplace, the terrace wrapping the glass corner.';

    private const V31_DESCRIPTION = 'A private collector\'s floor in two volumes — a low, coved gallery procession that lifts at a lit seam into a double-height living room glazed to the dusk city on two faces, the largest work living above the stone fireplace, a media wall and picture-lit art wall living in the lounge, the terrace wrapping the glass corner.';

    /** v3.0.0 chain bodies for every descriptor this pass mutates. */
    private const EXPECTED = [
        'lamp-pole' => ['id' => 'lamp-pole', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.55, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
        'lamp-shade' => ['id' => 'lamp-shade', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.55, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
        'bench-base' => ['id' => 'bench-base', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
        'sculpture-knot' => ['id' => 'sculpture-knot', 'primitive' => 'torus', 'at' => ['from' => 'junction', 'offset' => [0, 1.42, 1.5]], 'turn' => 'in', 'size' => [0.34, 0.1, 0.34], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
        // Context guard for the picture light (not mutated — presence/shape only).
        'art-wall-panel' => ['id' => 'art-wall-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_left_high', 'offset' => [0, 2.6, 0.03]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.8, 'size' => [1, 5.2, 0.06], 'material' => 'walnut', 'hangable' => ['y' => 2.6]],
    ];

    /** The mutated bodies (same ids, new values). */
    private const MUTATED = [
        'lamp-pole' => ['id' => 'lamp-pole', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [1.85, 0.8, 0.65]], 'turn' => 'in', 'size' => [0.055, 1.6, 0.055], 'material' => 'steel_dark'],
        'lamp-shade' => ['id' => 'lamp-shade', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [1.85, 1.68, 0.65]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.35]],
        'bench-base' => ['id' => 'bench-base', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'walnut', 'collide' => true],
        'sculpture-knot' => ['id' => 'sculpture-knot', 'primitive' => 'torus', 'at' => ['from' => 'junction', 'offset' => [0, 1.32, 1.5]], 'turn' => 'in', 'size' => [0.34, 0.1, 0.34], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
    ];

    /** M1 — the media wall furniture (corner unit, 45° into the room). */
    private const MEDIA_DESCRIPTOR_IDS = ['media-console', 'media-bezel'];
    private const MEDIA_DESCRIPTORS = [
        ['id' => 'media-console', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-2.3, 0.225, 0.85]], 'turn' => 'in', 'rot' => [0, 0.7854, 0], 'size' => [1.8, 0.45, 0.5], 'material' => 'basalt', 'collide' => true],
        ['id' => 'media-bezel', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-2.3, 0.94, 0.85]], 'turn' => 'in', 'rot' => [0, 0.7854, 0], 'size' => [1.66, 0.98, 0.08], 'material' => ['color' => '0x18130e', 'roughness' => 0.4, 'metalness' => 0.6], 'collide' => true],
    ];

    /** M2 — the bronze picture-light bar over the art-wall hang. */
    private const PICTURE_BAR_ID = 'art-wall-light-bar';
    private const PICTURE_BAR = ['id' => 'art-wall-light-bar', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left_high', 'offset' => [0, 3.72, 0.12]], 'turn' => 'in', 'size' => [1.6, 0.04, 0.1], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.4]];

    /** Fixture additions (anchor-resolved at build, same schema as the rig). */
    private const FIXTURE_IDS = ['art-wall-picture-light', 'media-glow'];
    private const NEW_FIXTURES = [
        ['id' => 'art-wall-picture-light', 'type' => 'point', 'anchor' => ['from' => 'wall_left_high', 'offset' => [0, 3.4, 0.8]], 'color' => '0xffd9a0', 'intensity' => 2.4, 'distance' => 5.5, 'decay' => 2.0, 'cast_shadow' => false],
        ['id' => 'media-glow', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [-1.85, 1.15, 1.65]], 'color' => '0xffe8c8', 'intensity' => 1.3, 'distance' => 4.5, 'decay' => 2.0, 'cast_shadow' => false],
    ];

    /** The viewer-side screen declaration (VenueDecorator.buildMediaWall). */
    private const MEDIA_WALL_CONFIG = ['bezel' => 'media-bezel', 'screen' => ['w' => 1.5, 'h' => 0.84], 'accent' => '0xd8a35a'];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $update   = [];
        $structure = $visual['structure'] ?? null;

        // ── M3/M4 — guarded per-descriptor swaps (lamp, bench, knot) ────
        if (is_array($structure)) {
            $byId = [];
            foreach ($structure as $i => $d) {
                if (is_array($d) && isset($d['id'])) {
                    $byId[$d['id']] = $i;
                }
            }

            foreach (self::MUTATED as $id => $newBody) {
                $expected = self::EXPECTED[$id] ?? null;
                $idx = $byId[$id] ?? null;
                if ($idx === null || $expected === null) {
                    $this->log("descriptor '{$id}' absent — skipped.");
                    continue;
                }
                if (!self::sameValue($structure[$idx], $expected)) {
                    $this->log("descriptor '{$id}' is admin-customised (or from another chain state) — left untouched.");
                    continue;
                }
                $structure[$idx] = $newBody;
                $this->log("descriptor '{$id}' updated to the v3.1.0 body.");
            }

            // ── M1 — media console + bezel (add when absent) ────────────
            // Context guard: the lounge anchor family must be present (the
            // console anchors 'glazing' like the sofa it serves).
            $loungeOk = isset($byId['sofa-base']);
            foreach (self::MEDIA_DESCRIPTORS as $d) {
                if (isset($byId[$d['id']])) {
                    $this->log("descriptor '{$d['id']}' already present — no change.");
                    continue;
                }
                if (!$loungeOk) {
                    $this->log("lounge anchor context missing (no sofa-base) — '{$d['id']}' not added.");
                    continue;
                }
                $structure[] = $d;
                $this->log("descriptor '{$d['id']}' added (media wall furniture).");
            }

            // ── M2 — the picture bar (add when absent + panel present) ──
            if (isset($byId[self::PICTURE_BAR_ID])) {
                $this->log("descriptor '" . self::PICTURE_BAR_ID . "' already present — no change.");
            } elseif (!isset($byId['art-wall-panel'])) {
                $this->log("art wall absent — '" . self::PICTURE_BAR_ID . "' not added.");
            } else {
                $structure[] = self::PICTURE_BAR;
                $this->log("descriptor '" . self::PICTURE_BAR_ID . "' added (art-wall picture light).");
            }

            if (!self::sameValue($structure, $visual['structure'] ?? null)) {
                $visual['structure'] = $structure;
                $update['visual_config'] = json_encode($visual);
            }
        } else {
            $this->log('no structure declared — left untouched.');
        }

        // ── Fixtures (add by absent id; schema matches the v3 rig) ──────
        $fixtureIds = [];
        foreach ($fixtures as $f) {
            if (is_array($f) && isset($f['id'])) {
                $fixtureIds[$f['id']] = true;
            }
        }
        $addedFixtures = [];
        foreach (self::NEW_FIXTURES as $f) {
            if (isset($fixtureIds[$f['id']])) {
                $this->log("fixture '{$f['id']}' already present — no change.");
                continue;
            }
            $fixtures[] = $f;
            $addedFixtures[] = $f['id'];
        }
        if ($addedFixtures) {
            $update['lighting_fixtures'] = json_encode($fixtures);
            $this->log('fixtures added: ' . implode(', ', $addedFixtures) . '.');
        }

        // ── The media_wall viewer declaration (absent-key union) ────────
        if (!array_key_exists('media_wall', $visual)) {
            $visual['media_wall'] = self::MEDIA_WALL_CONFIG;
            $update['visual_config'] = json_encode($visual);
            $this->log('visual_config.media_wall declared (viewer draws the Now Showing screen).');
        } else {
            $this->log('visual_config.media_wall already declared — left untouched.');
        }

        // ── Identity strings (exact-match guards) ───────────────────────
        if ((string) $row->version === '3.0.0') {
            $update['version'] = '3.1.0';
            $this->log('version 3.0.0 → 3.1.0.');
        }
        if ((string) $row->description === self::V3_DESCRIPTION) {
            $update['description'] = self::V31_DESCRIPTION;
            $this->log('description updated (media wall + picture light mentioned).');
        }

        if ($update !== []) {
            DB::table('venue_templates')->where('id', $row->id)->update($update);
        } else {
            $this->log('row already carries the v3.1.0 media wall — nothing to do.');
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $update   = [];
        $structure = $visual['structure'] ?? null;

        if (is_array($structure)) {
            $removeIds = array_merge(self::MEDIA_DESCRIPTOR_IDS, [self::PICTURE_BAR_ID]);
            $kept = [];
            $removed = [];
            foreach ($structure as $d) {
                if (is_array($d) && in_array($d['id'] ?? null, $removeIds, true)) {
                    $removed[] = $d['id'];
                    continue;
                }
                $kept[] = $d;
            }

            // Reverse the guarded swaps — only from the exact v3.1.0 bodies.
            $byId = [];
            foreach ($kept as $i => $d) {
                if (is_array($d) && isset($d['id'])) {
                    $byId[$d['id']] = $i;
                }
            }
            foreach (self::MUTATED as $id => $newBody) {
                $idx = $byId[$id] ?? null;
                $expected = self::EXPECTED[$id] ?? null;
                if ($idx === null || $expected === null) {
                    continue;
                }
                if (!self::sameValue($kept[$idx], $newBody)) {
                    $this->log("down(): descriptor '{$id}' is not at the v3.1.0 body — left untouched.");
                    continue;
                }
                $kept[$idx] = $expected;
            }

            if (!self::sameValue($kept, $structure)) {
                $visual['structure'] = $kept;
                $update['visual_config'] = json_encode($visual);
                $this->log('removed: ' . implode(', ', $removed) . ' ; descriptor swaps reversed.');
            }
        }

        $keptFixtures = [];
        $removedFixtures = [];
        foreach ($fixtures as $f) {
            if (is_array($f) && in_array($f['id'] ?? null, self::FIXTURE_IDS, true)) {
                $removedFixtures[] = $f['id'];
                continue;
            }
            $keptFixtures[] = $f;
        }
        if ($removedFixtures) {
            $update['lighting_fixtures'] = json_encode($keptFixtures);
            $this->log('fixtures removed: ' . implode(', ', $removedFixtures) . '.');
        }

        if (array_key_exists('media_wall', $visual)) {
            unset($visual['media_wall']);
            $update['visual_config'] = json_encode($visual);
            $this->log('visual_config.media_wall removed.');
        }

        if ((string) $row->version === '3.1.0') {
            $update['version'] = '3.0.0';
        }
        if ((string) $row->description === self::V31_DESCRIPTION) {
            $update['description'] = self::V3_DESCRIPTION;
        }

        if ($update !== []) {
            DB::table('venue_templates')->where('id', $row->id)->update($update);
            $this->log('down() complete — row restored toward v3.0.0.');
        }
    }

    /**
     * Semantic equality for venue-config JSON shapes (the 000008 rules,
     * compact form): numbers by value (int/float interchangeable), maps
     * key-order-free, lists strictly ordered, strings/bools/null strict.
     */
    private function sameValue($a, $b): bool
    {
        if (is_array($a) && is_array($b)) {
            $isList = static fn ($v): bool => array_keys($v) === range(0, count($v) - 1);
            if ($isList($a) || $isList($b)) {
                if (!$isList($a) || !$isList($b) || count($a) !== count($b)) {
                    return false;
                }
                foreach ($a as $i => $v) {
                    if (!self::sameValue($v, $b[$i])) {
                        return false;
                    }
                }
                return true;
            }
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::sameValue($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        if (is_int($a) || is_float($a)) {
            return (is_int($b) || is_float($b)) && abs((float) $a - (float) $b) < PHP_FLOAT_EPSILON;
        }
        return $a === $b;
    }

    private function log(string $message): void
    {
        echo '[luxury-penthouse-media-wall] ' . $message . PHP_EOL;
    }
};

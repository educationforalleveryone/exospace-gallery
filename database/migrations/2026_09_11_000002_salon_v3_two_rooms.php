<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE SALON v3.0.0 — "two rooms" (second field pass: the curtain, done right)
 * ===========================================================================
 *
 * WHY (the field reports, verbatim anchors)
 * -----------------------------------------
 *   • v2.1 report: "i can walk past through it" — the phantom the visitor
 *     photographed turned out to be the mis-yawed side trim (fixed in v2.1).
 *     But the SAME report loved the accidental two-room read: "i liked the
 *     two room kind structure better even if it was a bug, the rooms
 *     separated by a curtain not wall … both rooms contains artworks, the
 *     curtain was the only problem though it was not looking like the
 *     curtain … it also make this venue unique because none of the other
 *     venues has two rooms".
 *   • v2.1 deploy report: "problem 2 about door still exists its width is
 *     still same and it does not look like a door" — while the browser
 *     console proved the NEW bundle was live and the payload was NOT:
 *     "[structure] base-left … auto-aligned to the wall tangent. Fix the
 *     payload." The v2.1 door lives in the DB payload; the bundle cannot
 *     invent it.
 *
 * THE LESSON OF v2.1 (why this migration is shaped differently)
 * -------------------------------------------------------------
 *   v2.1 rewrote the payload under BYTE-EXACT v2.0 element guards. The
 *   production row never matched every guard, the migration silently wrote
 *   nothing (migrations DO run at container start — the guards simply did
 *   not match), and the site kept serving the stale v2.0 payload. Exact-
 *   form guards are the wrong tool for a product-owned template row: they
 *   cannot tell "operator edit" from "historical drift", and every mismatch
 *   is a silent no-op.
 *
 *   This migration therefore rewrites the salon template row FORCIBLY,
 *   guarded only by the payload VERSION:
 *     • version < 3.0.0 (v1 stub, v2.0.0, drifted v2.1, anything else) and
 *       ≠ 3.0.0 → rewritten to the canonical v3.0.0 payload;
 *     • version === '3.0.0' → no-op (idempotent re-run);
 *     • down() restores the canonical v2.1.0 payload only from an exact
 *       '3.0.0' row (a well-defined reversal).
 *   The venue_templates row is product-owned: galleries re-skin
 *   curator-lane keys through the exporter's venue-authority merge and can
 *   never carry venue structure, so a forced rewrite cannot destroy user
 *   content. An operator who hand-edited the template row itself owns
 *   re- applying it — that is what the version column now documents.
 *
 * THE v3 IDENTITY (payload summary)
 * ---------------------------------
 *   • placement.room_divider { at 0.5, opening 2.4, keep 0.55,
 *     door_keep 1.15, spacing 2.4 } — the square hang becomes SIX wall
 *     segments split by the curtain plane; both rooms receive works at
 *     every count 5–30; the hero keeps a dead-centre slot on the front
 *     wall so the arrival reads it THROUGH the opening. keep_clear is
 *     superseded (the door keep lives in room_divider).
 *   • structure 'salon-curtain' — the new parametric 'curtain' primitive:
 *     two tied-back velvet panels (sine folds, gathered waist, hem wave),
 *     brass rod, brackets, finials, heading rings, tie bands. collide:
 *     true — each panel registers a tight world AABB, so the 2.4 m
 *     opening IS the walk gap. You cannot walk through the fabric; you
 *     walk through the opening.
 *   • Door, third attempt: 2 × 0.92 m leaves (1.84 m clear, +24 % over
 *     v2.1, +100 % over v2.0), 2.62 m tall, two raised panels per leaf,
 *     2.06 m entablature, bronze knobs AND backplates at both meeting
 *     stiles, ivory overdoor. room_divider.door_keep = 1.15 holds the
 *     wall so the hang cannot crowd it.
 *   • Two ceiling roses (one per room — the v2 single rose would sit on
 *     the curtain line).
 *   • Fresh installs: the seeder ships v3.0.0 directly; this migration
 *     no-ops there (version already 3.0.0).
 *   • Payloads stay pinned byte-equal (seeder ↔ this file) by
 *     VenueSalonIterationTest.
 */

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }
        if ($row->version === '3.0.0') {
            return; // already v3 — idempotent re-run
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update([
                'visual_config' => json_encode($this->v3Payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'description'   => $this->v3Description(),
                'tags'          => json_encode(['salon', 'warm', 'intimate', 'portrait', 'two-room']),
                'version'       => '3.0.0',
            ]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'visual_config', 'version', 'description']);
        if (!$row || $row->version !== '3.0.0') {
            return; // nothing this migration owns
        }

        // Reversal target: the canonical v2.1.0 payload (the version of
        // record immediately before v3). Rebuilt from the canonical v3 row
        // by reversing the v3 deltas — well-defined because the guard is
        // an exact version match.
        $vc = json_decode((string) $row->visual_config, true);
        if (is_array($vc)) {
            // 1. placement: room_divider → keep_clear
            if (isset($vc['placement']) && is_array($vc['placement'])) {
                unset($vc['placement']['room_divider']);
                $vc['placement']['keep_clear'] = ['wall' => 'back', 'width' => 1.9, 'max_width' => 1.2];
            }
            // 2. structure: drop the curtain, swap door v3 → v2.1, roses → one
            if (isset($vc['structure']) && is_array($vc['structure'])) {
                $st = array_values(array_filter($vc['structure'], fn ($e) => is_array($e) && ($e['id'] ?? null) !== 'salon-curtain'));
                $byId = [];
                foreach ($st as $i => $el) {
                    if (isset($el['id'])) { $byId[$el['id']] = $i; }
                }
                $v3DoorIds = ['door-leaf-l', 'door-leaf-r', 'door-panel-ll', 'door-panel-lu', 'door-panel-rl', 'door-panel-ru', 'door-jamb-l', 'door-jamb-r', 'door-head', 'door-overdoor', 'door-knob-l', 'door-knob-r', 'door-plate-l', 'door-plate-r'];
                if (count(array_intersect($v3DoorIds, array_keys($byId))) === count($v3DoorIds)) {
                    $firstIdx = $byId['door-leaf-l'];
                    $dropIdx  = array_map(fn ($id) => $byId[$id], $v3DoorIds);
                    $head = array_slice($st, 0, $firstIdx);
                    $tail = array_values(array_filter(
                        array_slice($st, $firstIdx),
                        fn ($_, $k) => !in_array($firstIdx + $k, $dropIdx, true),
                        ARRAY_FILTER_USE_BOTH
                    ));
                    $st = array_values(array_merge($head, $this->v21Door(), $tail));
                }
                $byId = [];
                foreach ($st as $i => $el) {
                    if (isset($el['id'])) { $byId[$el['id']] = $i; }
                }
                $v3RoseIds = ['rose-disc-a', 'rose-ring-a', 'rose-glow-a', 'rose-disc-b', 'rose-ring-b', 'rose-glow-b'];
                if (count(array_intersect($v3RoseIds, array_keys($byId))) === count($v3RoseIds)) {
                    $firstIdx = $byId['rose-disc-a'];
                    $dropIdx  = array_map(fn ($id) => $byId[$id], $v3RoseIds);
                    $head = array_slice($st, 0, $firstIdx);
                    $tail = array_values(array_filter(
                        array_slice($st, $firstIdx),
                        fn ($_, $k) => !in_array($firstIdx + $k, $dropIdx, true),
                        ARRAY_FILTER_USE_BOTH
                    ));
                    $st = array_values(array_merge($head, $this->v21Rose(), $tail));
                }
                $vc['structure'] = $st;
            }
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update([
                'visual_config' => json_encode($vc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'description'   => $this->v21Description(),
                'tags'          => json_encode(['salon', 'warm', 'intimate', 'portrait']),
                'version'       => '2.1.0',
            ]);
    }

    private function v3Description(): string
    {
        return 'A warm collector’s suite of two rooms: a full-height olive velvet curtain, tied back over a brass rod, divides the receiving room from the gallery room — both hung salon-style, large at eye level and smaller above, between walnut trim and a picture rail under a coved warm light. The arrival frames its hero through the curtain opening; the wide walnut double door closes the enfilade behind you. Made for studies, prints, photography and portrait formats.';
    }

    private function v21Description(): string
    {
        return 'A warm collector’s salon in the domestic tradition: a doorcase behind you, a hero wall ahead, works hung salon-style — large at eye level, smaller above — between walnut trim and a picture rail, under a coved warm light. Made for studies, prints, photography and portrait formats.';
    }

    // ── v2.1 reversal parts ─────────────────────────────────────────────
    private function v21Door(): array
    {
        return [
            ['id' => 'door-leaf-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 1.26, 0.035]], 'size' => [0.74, 2.52, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-leaf-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 1.26, 0.035]], 'size' => [0.74, 2.52, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-panel-ll', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 0.62, 0.061]], 'size' => [0.46, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-panel-lu', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 1.85, 0.061]], 'size' => [0.46, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-panel-rl', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 0.62, 0.061]], 'size' => [0.46, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-panel-ru', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 1.85, 0.061]], 'size' => [0.46, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            ['id' => 'door-jamb-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.785, 1.26, 0.02]], 'size' => [0.09, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            ['id' => 'door-jamb-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.785, 1.26, 0.02]], 'size' => [0.09, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            ['id' => 'door-head', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.595, 0.02]], 'size' => [1.66, 0.15, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            ['id' => 'door-overdoor', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.99, 0.024]], 'size' => [1.62, 0.64, 0.024], 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
            ['id' => 'door-knob', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [-0.13, 1.16, 0.08]], 'size' => [0.05, 0.05, 0.05], 'material' => 'bronze', 'tier_floor' => 'low'],
        ];
    }

    private function v21Rose(): array
    {
        return [
            ['id' => 'rose-disc', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.78, 0]], 'size' => [1.0, 0.04], 'params' => ['seg' => 24], 'material' => ['color' => '0xd0c3a8', 'roughness' => 0.9, 'metalness' => 0.0], 'tier_floor' => 'low'],
            ['id' => 'rose-ring', 'primitive' => 'torus', 'at' => ['from' => 'center', 'offset' => [0, 3.755, 0]], 'rot' => [1.5707963, 0, 0], 'size' => [0.5, 0.05], 'params' => ['seg' => 24, 'seg2' => 32], 'material' => 'bronze', 'tier_floor' => 'low'],
            ['id' => 'rose-glow', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.768, 0]], 'size' => [0.32, 0.02], 'params' => ['seg' => 20], 'material' => ['color' => '0xfff1dc', 'emissive' => '0xffe2b8', 'emissiveIntensity' => 0.9], 'tier_floor' => 'low'],
        ];
    }

    // ── The canonical v3.0.0 payload (byte-pinned against the seeder) ────
    private function v3Payload(): array
    {
        return [
            'wall_height'             => 3.8,
            'wall_depth'              => 0.15,
            'ceiling_type'            => 'flat',
            'ceiling_color'           => '0xd8cbb0',
            'ceiling_height'          => 3.8,
            'background_color'        => '0x171310',
            'fog_color'               => '0x171310',
            'fog_near'                => 22,
            'fog_far'                 => 70,
            'ambient_color'           => '0xffe9cf',
            'ambient_intensity'       => 0.5,
            'spot_intensity'          => 1.5,
            'fill_intensity'          => 0.8,
            'tone_mapping_exposure'   => 1.0,
            'frame_override'          => 'classic',
            'environment'             => 'studio',
            'env_intensity'           => 0.18,
            'hemisphere_intensity'    => 0.22,
            'hemisphere_sky_color'    => '0xfff1dc',
            'hemisphere_ground_color' => '0x4a3f33',
            'artwork_light_base'      => 0.3,
            'artwork_light_pool_cap'  => 12,
            'structure_pass'          => 'rooms',
            'placement'               => [
                'density'          => 'intimate',
                'pair_orientation' => true,
                'focal_wall'       => 'front',
                'wall_length_cap'  => 12.6,
                'salon_rows'       => 2,
                'upper_row_y'      => 2.98,
                'room_divider'     => ['at' => 0.5, 'opening' => 2.4, 'keep' => 0.55, 'door_keep' => 1.15, 'spacing' => 2.4],
                'row_caps'         => [
                    ['maxWidth' => 2.0, 'maxHeight' => 1.45],
                    ['maxWidth' => 1.7, 'maxHeight' => 0.84],
                ],
            ],
            'structure'               => [
                ['id' => 'base-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'base-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'base-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.09, 0.0]], 'turn' => 'in', 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'base-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.09, 0.0]], 'turn' => 'in', 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'field-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                ['id' => 'field-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                ['id' => 'field-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 1.98, 0.011]], 'turn' => 'in', 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                ['id' => 'field-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 1.98, 0.011]], 'turn' => 'in', 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'rail-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'rail-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.53, 0.0]], 'turn' => 'in', 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.53, 0.0]], 'turn' => 'in', 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'cornice-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                ['id' => 'cornice-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                ['id' => 'cornice-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.72, 0.0]], 'turn' => 'in', 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                ['id' => 'cornice-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.72, 0.0]], 'turn' => 'in', 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                ['id' => 'cove-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                ['id' => 'cove-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                ['id' => 'cove-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.615, 0.0]], 'turn' => 'in', 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                ['id' => 'cove-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.615, 0.0]], 'turn' => 'in', 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                ['id' => 'door-leaf-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.46, 1.31, 0.05]], 'size' => [0.92, 2.62, 0.045], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-leaf-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.46, 1.31, 0.05]], 'size' => [0.92, 2.62, 0.045], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-panel-ll', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.46, 0.71, 0.085]], 'size' => [0.56, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-panel-lu', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.46, 1.86, 0.085]], 'size' => [0.56, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-panel-rl', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.46, 0.71, 0.085]], 'size' => [0.56, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-panel-ru', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.46, 1.86, 0.085]], 'size' => [0.56, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                ['id' => 'door-jamb-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.975, 1.31, 0.03]], 'size' => [0.11, 2.62, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'door-jamb-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.975, 1.31, 0.03]], 'size' => [0.11, 2.62, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'door-head', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.7, 0.03]], 'size' => [2.06, 0.16, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'door-overdoor', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.09, 0.024]], 'size' => [2.02, 0.6, 0.024], 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                ['id' => 'door-knob-l', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [-0.08, 1.16, 0.095]], 'size' => [0.052, 0.052, 0.052], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'door-knob-r', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [0.08, 1.16, 0.095]], 'size' => [0.052, 0.052, 0.052], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'door-plate-l', 'primitive' => 'cylinder', 'at' => ['from' => 'wall_back', 'offset' => [-0.08, 1.16, 0.078]], 'rot' => [1.5707963, 0, 0], 'size' => [0.07, 0.012], 'params' => ['seg' => 14], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'door-plate-r', 'primitive' => 'cylinder', 'at' => ['from' => 'wall_back', 'offset' => [0.08, 1.16, 0.078]], 'rot' => [1.5707963, 0, 0], 'size' => [0.07, 0.012], 'params' => ['seg' => 14], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'salon-curtain', 'primitive' => 'curtain', 'at' => ['from' => 'center', 'offset' => [0, 0, 0]], 'params' => ['opening' => 2.4, 'rod_y' => 3.12, 'top_y' => 3.04, 'hem_y' => 0.02, 'amplitude' => 0.085, 'folds' => 6, 'tie_y' => 1.12, 'gather' => 0.15, 'hem_gather' => 0.06, 'bracket' => 0.16, 'hardware' => 'bronze', 'seed' => 'salon-two-rooms'], 'material' => ['color' => '0x565c49', 'roughness' => 0.96, 'metalness' => 0.0, 'side' => 'double'], 'collide' => true, 'tier_floor' => 'low'],
                ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.34, 0.85]], 'size' => [1.7, 0.1, 0.46], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [-0.72, 0.145, 0.85]], 'size' => [0.1, 0.29, 0.42], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0.72, 0.145, 0.85]], 'size' => [0.1, 0.29, 0.42], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [2.2, 0.012, 2.2]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 2.6], 'material' => ['color' => '0x74604a', 'roughness' => 1.0, 'metalness' => 0.0], 'tier_floor' => 'low'],
                ['id' => 'chair-a-seat', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.75, 0.275, 1.75]], 'rot' => [0, -0.785398, 0], 'size' => [0.7, 0.35, 0.7], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'collide' => true, 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                ['id' => 'chair-a-back', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [3.0, 0.7, 1.5]], 'rot' => [0, -0.785398, 0], 'size' => [0.7, 0.6, 0.14], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                ['id' => 'chair-a-arm-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.5, 0.55, 1.5]], 'rot' => [0, -0.785398, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                ['id' => 'chair-a-arm-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [3.0, 0.55, 2.0]], 'rot' => [0, -0.785398, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                ['id' => 'chair-b-seat', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.75, 0.275, 2.75]], 'rot' => [0, 2.356194, 0], 'size' => [0.7, 0.35, 0.7], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'collide' => true, 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                ['id' => 'chair-b-back', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.5, 0.7, 3.0]], 'rot' => [0, 2.356194, 0], 'size' => [0.7, 0.6, 0.14], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                ['id' => 'chair-b-arm-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.5, 0.55, 2.5]], 'rot' => [0, 2.356194, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                ['id' => 'chair-b-arm-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.0, 0.55, 3.0]], 'rot' => [0, 2.356194, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                ['id' => 'table-top', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.52, 2.25]], 'size' => [0.64, 0.05], 'params' => ['seg' => 20], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-table', 'tier_floor' => 'low'],
                ['id' => 'table-stem', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.26, 2.25]], 'size' => [0.07, 0.46], 'params' => ['seg' => 10], 'material' => 'wood_dark', 'merge' => 'salon-table', 'tier_floor' => 'low'],
                ['id' => 'table-base', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.015, 2.25]], 'size' => [0.36, 0.03], 'params' => ['seg' => 16], 'material' => 'wood_dark', 'merge' => 'salon-table', 'tier_floor' => 'low'],
                ['id' => 'rose-disc-a', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.78, 2.4]], 'size' => [1.0, 0.04], 'params' => ['seg' => 24], 'material' => ['color' => '0xd0c3a8', 'roughness' => 0.9, 'metalness' => 0.0], 'tier_floor' => 'low'],
                ['id' => 'rose-ring-a', 'primitive' => 'torus', 'at' => ['from' => 'center', 'offset' => [0, 3.755, 2.4]], 'rot' => [1.5707963, 0, 0], 'size' => [0.5, 0.05], 'params' => ['seg' => 24, 'seg2' => 32], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'rose-glow-a', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.768, 2.4]], 'size' => [0.32, 0.02], 'params' => ['seg' => 20], 'material' => ['color' => '0xfff1dc', 'emissive' => '0xffe2b8', 'emissiveIntensity' => 0.9], 'tier_floor' => 'low'],
                ['id' => 'rose-disc-b', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.78, -2.4]], 'size' => [1.0, 0.04], 'params' => ['seg' => 24], 'material' => ['color' => '0xd0c3a8', 'roughness' => 0.9, 'metalness' => 0.0], 'tier_floor' => 'low'],
                ['id' => 'rose-ring-b', 'primitive' => 'torus', 'at' => ['from' => 'center', 'offset' => [0, 3.755, -2.4]], 'rot' => [1.5707963, 0, 0], 'size' => [0.5, 0.05], 'params' => ['seg' => 24, 'seg2' => 32], 'material' => 'bronze', 'tier_floor' => 'low'],
                ['id' => 'rose-glow-b', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.768, -2.4]], 'size' => [0.32, 0.02], 'params' => ['seg' => 20], 'material' => ['color' => '0xfff1dc', 'emissive' => '0xffe2b8', 'emissiveIntensity' => 0.9], 'tier_floor' => 'low'],
            ],
            'post_fx'                 => ['bloom' => false, 'vignette' => true, 'vignette_darkness' => 0.38, 'vignette_offset' => 1.1, 'vignette_blend' => 'black'],
        ];
    }
};

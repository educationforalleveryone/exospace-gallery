<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OUTDOOR SCULPTURE GARDEN v4.0.0 — "The Sculpture Park" (asset-driven reset).
 *
 * WHAT THE USER'S SCREENSHOT VERDICT FOUND (design reset, not taste):
 *   • The procedural asset vocabulary itself failed: icosahedron/cone
 *     primitive trees, cylinder stepping stones, box hedge ring — the garden
 *     read as a procedural game level, not a premium exhibition landscape.
 *   • The tripod easels read as yard-sale signage, not exhibition hardware.
 *   • The tall pedestal read as a trophy plinth, not a sculpture-park plinth.
 *
 * THE SIGNATURE (ships in the JS bundle — GardenAssets.js + the rebuilt
 * VenueDecorator garden body + GardenLayout role-tagged anchors +
 * ArtworkPlacer's museum panel stands; this migration carries only the DB
 * half, the same split every deepening iteration uses):
 *   the garden becomes ASSET-DRIVEN. The v3 landscape plan (terrain, walks,
 *   courts, clearances, validator) is kept; every living element is now a
 *   NAMED EXTERNAL GLB the owner supplies under
 *   public/assets/venues/sculpture-garden/. Missing files skip their layer
 *   gracefully — the base environment (gravel walks, panel stands, hero
 *   travertine court, sky) stands alone.
 *
 * THIS MIGRATION (DB side only):
 *   visual_config.garden : the asset manifest (assets_base + 7 role
 *                          filenames) added beside sky_environment.
 *   visual_config        : horizon haze retune (background/fog).
 *   material_config      : lawn colour retune + calmer tile scale.
 *   description          : verifiable copy (stone promenade → gravel walk).
 *   version              : 3.0.0 → 4.0.0 under guard.
 *
 * GUARDING (same contract as the v3 garden / cyber / zen migrations):
 *   every rewrite fires ONLY while the stored value still equals the
 *   previously seeded value (strings strictly, numbers numerically). A
 *   super-admin's custom value is never touched; the garden asset manifest
 *   is added only when the garden block carries no assets_base yet.
 *   Idempotent; down() reverses each rewrite under the same exact-match
 *   guard. Paired with the seeder (fresh-install baseline).
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

    private function gardenAssets(): array
    {
        return [
            'tree_large'  => 'tree_large_01.glb',
            'tree_medium' => 'tree_medium_01.glb',
            'tree_accent' => 'tree_medium_02.glb',
            'shrub'       => 'shrub_01.glb',
            'grass'       => 'grass_clump_01.glb',
            'boulder'     => 'boulder_01.glb',
            'bench'       => 'bench_01.glb',
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        // ── visual_config ────────────────────────────────────────────────
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color' => ['from' => '0xd6e0e2', 'to' => '0xdfe2d1'],
            'fog_color'        => ['from' => '0xd6e0e2', 'to' => '0xdfe2d1'],
            // The v4 landscape needs a larger field: the asset-driven gate
            // pair + protected arrival corridor consume lawn the v3 courts
            // used. A bigger field also serves the brief's "spacious" read.
            'field_radius_bonus' => ['from' => 2.2, 'to' => 2.6],
            'field_radius_min'   => ['from' => 12.5, 'to' => 14],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Garden block: add the asset manifest only while the owner has not
        // declared one (an existing assets_base is never overwritten).
        if (is_array($vc['garden'] ?? null) && !isset($vc['garden']['assets_base'])) {
            $vc['garden']['assets_base'] = '/assets/venues/sculpture-garden/';
            $vc['garden']['assets'] = $this->gardenAssets();
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config (lawn retune) ────────────────────────────────
        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'       => ['from' => '0x3a6a2a', 'to' => '0x5e7a46'],
                'floor_tile_meters' => ['from' => 2.0, 'to' => 3.0],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        // ── description (customer-facing truth) ──────────────────────────
        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        $v4Description = 'A curated open-air exhibition. A gravel walk leads from the tree-lined gate to a bronze centrepiece on a travertine court, then on to works presented on outdoor museum stands across lawns and sculpture clearings, framed by mature trees and a distant treeline.';
        if ((string) $row->description === $v3Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v4Description]);
        }

        // ── version ──────────────────────────────────────────────────────
        if ($this->guardedEquals($row->version, '3.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '4.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color' => ['from' => '0xdfe2d1', 'to' => '0xd6e0e2'],
            'fog_color'        => ['from' => '0xdfe2d1', 'to' => '0xd6e0e2'],
            'field_radius_bonus' => ['from' => 2.6, 'to' => 2.2],
            'field_radius_min'   => ['from' => 14, 'to' => 12.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Remove the asset manifest only while it still equals what up() wrote.
        if (is_array($vc['garden'] ?? null)
            && ($vc['garden']['assets_base'] ?? null) === '/assets/venues/sculpture-garden/'
            && ($vc['garden']['assets'] ?? null) === $this->gardenAssets()) {
            unset($vc['garden']['assets_base'], $vc['garden']['assets']);
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'       => ['from' => '0x5e7a46', 'to' => '0x3a6a2a'],
                'floor_tile_meters' => ['from' => 3.0, 'to' => 2.0],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        $v4Description = 'A curated open-air exhibition. A gravel walk leads from the tree-lined gate to a bronze centrepiece on a travertine court, then on to works presented on outdoor museum stands across lawns and sculpture clearings, framed by mature trees and a distant treeline.';
        if ((string) $row->description === $v4Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v3Description]);
        }

        if ($this->guardedEquals($row->version, '4.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '3.0.0']);
        }
    }
};

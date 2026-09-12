<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ── The ten v2.0.0 side-wall descriptors (historical forms) ─────────
    private function v2SideElements(): array
    {
        return [
            'base-left' => ['id' => 'base-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'base-right' => ['id' => 'base-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'field-left' => ['id' => 'field-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
            'field-right' => ['id' => 'field-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
            'rail-left' => ['id' => 'rail-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'rail-right' => ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'cornice-left' => ['id' => 'cornice-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
            'cornice-right' => ['id' => 'cornice-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
            'cove-left' => ['id' => 'cove-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
            'cove-right' => ['id' => 'cove-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
        ];
    }

    private function v21SideElements(): array
    {
        $out = [];
        foreach ($this->v2SideElements() as $id => $el) {
            $healed = $el;
            $at = $el['at'];
            unset($healed['at']);
            $healed = ['id' => $el['id'], 'primitive' => $el['primitive'], 'at' => $at, 'turn' => 'in'] + $healed;
            $out[$id] = $healed;
        }
        return $out;
    }

    // ── The v2.0.0 doorcase (7 descriptors, historical forms) ────────────
    private function v2DoorIds(): array
    {
        return ['door-leaf', 'door-panel', 'door-jamb-l', 'door-jamb-r', 'door-head', 'door-overdoor', 'door-knob'];
    }

    private function v2DoorElements(): array
    {
        return [
            'door-leaf' => ['id' => 'door-leaf', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.21, 0.0]], 'size' => [0.92, 2.42, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-panel' => ['id' => 'door-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.21, 0.026]], 'size' => [0.68, 1.9, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-jamb-l' => ['id' => 'door-jamb-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.475, 1.26, 0.02]], 'size' => [0.1, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-jamb-r' => ['id' => 'door-jamb-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.475, 1.26, 0.02]], 'size' => [0.1, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-head' => ['id' => 'door-head', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.59, 0.02]], 'size' => [1.05, 0.14, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-overdoor' => ['id' => 'door-overdoor', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.95, 0.008]], 'size' => [1.05, 0.72, 0.024], 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
            'door-knob' => ['id' => 'door-knob', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [0.33, 1.08, 0.035]], 'size' => [0.044, 0.044, 0.044], 'material' => 'bronze', 'tier_floor' => 'low'],
        ];
    }

    // ── The v2.1.0 doorcase: portes à deux vantaux (11 descriptors) ──────
    private function v21DoorElements(): array
    {
        return [
            'door-leaf-l' => ['id' => 'door-leaf-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 1.26, 0.035]], 'size' => [0.74, 2.52, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-leaf-r' => ['id' => 'door-leaf-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 1.26, 0.035]], 'size' => [0.74, 2.52, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-panel-ll' => ['id' => 'door-panel-ll', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 0.62, 0.061]], 'size' => [0.46, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-panel-lu' => ['id' => 'door-panel-lu', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.37, 1.85, 0.061]], 'size' => [0.46, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-panel-rl' => ['id' => 'door-panel-rl', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 0.62, 0.061]], 'size' => [0.46, 0.92, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-panel-ru' => ['id' => 'door-panel-ru', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.37, 1.85, 0.061]], 'size' => [0.46, 1.02, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
            'door-jamb-l' => ['id' => 'door-jamb-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.785, 1.26, 0.02]], 'size' => [0.09, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-jamb-r' => ['id' => 'door-jamb-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.785, 1.26, 0.02]], 'size' => [0.09, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-head' => ['id' => 'door-head', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.595, 0.02]], 'size' => [1.66, 0.15, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
            'door-overdoor' => ['id' => 'door-overdoor', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.99, 0.024]], 'size' => [1.62, 0.64, 0.024], 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
            'door-knob' => ['id' => 'door-knob', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [-0.13, 1.16, 0.08]], 'size' => [0.05, 0.05, 0.05], 'material' => 'bronze', 'tier_floor' => 'low'],
        ];
    }

    private function v2KeepClear(): array
    {
        return ['wall' => 'back', 'width' => 1.05, 'max_width' => 1.6];
    }

    private function v21KeepClear(): array
    {
        return ['wall' => 'back', 'width' => 1.9, 'max_width' => 1.2];
    }

    private function arraysEqual($a, $b): bool
    {
        return is_array($a) && is_array($b)
            && array_keys($a) === array_keys($b)
            && $a == $b; // loose on values, strict on key ORDER (byte-stable payload)
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }
        $vc = json_decode((string) $row->visual_config, true);
        if (!is_array($vc)) {
            return;
        }

        $changed = false;

        // ── 1. Side-element heal (per-element exact guard) ───────────────
        if (isset($vc['structure']) && is_array($vc['structure'])) {
            $v2Side  = $this->v2SideElements();
            $v21Side = $this->v21SideElements();
            foreach ($vc['structure'] as $i => $el) {
                $id = is_array($el) ? ($el['id'] ?? null) : null;
                if ($id && isset($v2Side[$id]) && $this->arraysEqual($el, $v2Side[$id])) {
                    $vc['structure'][$i] = $v21Side[$id];
                    $changed = true;
                }
            }

            // ── 2. Door-group heal (all-seven exact guard, spliced) ──────
            $byId = [];
            foreach ($vc['structure'] as $i => $el) {
                if (is_array($el) && isset($el['id'])) {
                    $byId[$el['id']] = $i;
                }
            }
            $v2Door = $this->v2DoorElements();
            $doorIsV2 = true;
            foreach (array_keys($v2Door) as $doorId) {
                $i = $byId[$doorId] ?? null;
                if ($i === null || !$this->arraysEqual($vc['structure'][$i], $v2Door[$doorId])) {
                    $doorIsV2 = false;
                    break;
                }
            }
            if ($doorIsV2) {
                $v21Door = $this->v21DoorElements();
                $firstIdx = $byId['door-leaf'];
                $dropIdx  = array_map(fn ($id) => $byId[$id], array_keys($v2Door));
                $head  = array_slice($vc['structure'], 0, $firstIdx);
                $tail  = array_values(array_filter(
                    array_slice($vc['structure'], $firstIdx),
                    fn ($_, $k) => !in_array($firstIdx + $k, $dropIdx, true),
                    ARRAY_FILTER_USE_BOTH
                ));
                $vc['structure'] = array_values(array_merge(
                    $head,
                    array_values($v21Door),
                    $tail
                ));
                $changed = true;
            }
        }

        // ── 3. keep_clear heal (exact block guard) ───────────────────────
        if (isset($vc['placement']['keep_clear'])
            && $this->arraysEqual($vc['placement']['keep_clear'], $this->v2KeepClear())) {
            $vc['placement']['keep_clear'] = $this->v21KeepClear();
            $changed = true;
        }

        if (!$changed) {
            return; // already v2.1, or admin-authored — write nothing
        }

        $vcJson = json_encode($vc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update([
                'visual_config' => $vcJson,
                'version'       => $row->version === '2.0.0' ? '2.1.0' : $row->version,
            ]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return;
        }
        $vc = json_decode((string) $row->visual_config, true);
        if (!is_array($vc)) {
            return;
        }

        $changed = false;

        if (isset($vc['structure']) && is_array($vc['structure'])) {
            $v2Side  = $this->v2SideElements();
            $v21Side = $this->v21SideElements();
            foreach ($vc['structure'] as $i => $el) {
                $id = is_array($el) ? ($el['id'] ?? null) : null;
                if ($id && isset($v21Side[$id]) && $this->arraysEqual($el, $v21Side[$id])) {
                    $vc['structure'][$i] = $v2Side[$id];
                    $changed = true;
                }
            }

            $byId = [];
            foreach ($vc['structure'] as $i => $el) {
                if (is_array($el) && isset($el['id'])) {
                    $byId[$el['id']] = $i;
                }
            }
            $v21Door = $this->v21DoorElements();
            $doorIsV21 = true;
            foreach (array_keys($v21Door) as $doorId) {
                $i = $byId[$doorId] ?? null;
                if ($i === null || !$this->arraysEqual($vc['structure'][$i], $v21Door[$doorId])) {
                    $doorIsV21 = false;
                    break;
                }
            }
            if ($doorIsV21) {
                $v2Door = $this->v2DoorElements();
                $firstIdx = $byId['door-leaf-l'];
                $dropIdx  = array_map(fn ($id) => $byId[$id], array_keys($v21Door));
                $head = array_slice($vc['structure'], 0, $firstIdx);
                $tail = array_values(array_filter(
                    array_slice($vc['structure'], $firstIdx),
                    fn ($_, $k) => !in_array($firstIdx + $k, $dropIdx, true),
                    ARRAY_FILTER_USE_BOTH
                ));
                $vc['structure'] = array_values(array_merge(
                    $head,
                    array_values($v2Door),
                    $tail
                ));
                $changed = true;
            }
        }

        if (isset($vc['placement']['keep_clear'])
            && $this->arraysEqual($vc['placement']['keep_clear'], $this->v21KeepClear())) {
            $vc['placement']['keep_clear'] = $this->v2KeepClear();
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $vcJson = json_encode($vc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update([
                'visual_config' => $vcJson,
                'version'       => $row->version === '2.1.0' ? '2.0.0' : $row->version,
            ]);
    }
};

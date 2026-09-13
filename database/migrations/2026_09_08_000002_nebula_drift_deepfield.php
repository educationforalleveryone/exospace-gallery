<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'nebula-drift';

    private const OLD_VERSION = '1.0.0';
    private const NEW_VERSION = '2.0.0';

    private const OLD_DESCRIPTION =
        'Artworks drift through a cosmic cloud — distant stars and a purple nebula with quiet depth between them. For digital art and otherworldly exhibitions.';
    private const NEW_DESCRIPTION =
        'A deep-field nebula surrounds the exhibition — layered cosmic masses drifting along a tilted galactic band, a slow stardrift current, and a lone meridian ring overhead. Artworks float above pools of light on a dark starlit floor.';

    private const OLD_FIXTURES = [
        [
            'id'          => 'nebula-center',
            'type'        => 'point',
            'position'    => [0, 5, 0],
            'color'       => '0x8844ff',
            'intensity'   => 0.5,
            'cast_shadow' => false,
            'distance'    => 30,
            'decay'       => 1.5,
        ],
    ];

    private const NEW_FIXTURES = [
        [
            'id'          => 'nebula-key',
            'type'        => 'directional',
            'position'    => [30, 45, -18],
            'color'       => '0x9ab0e0',
            'intensity'   => 0.45,
            'cast_shadow' => false,
        ],
    ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];

        // Changed values — only while still equal to the seeded v1.0.0 value.
        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $from) {
                $visual[$key] = $to;
            }
        }

        // Added keys — union (absent key only).
        foreach ($this->addedVisualKeys() as $key => $value) {
            if (!array_key_exists($key, $visual)) {
                $visual[$key] = $value;
            }
        }

        foreach ($this->removedVisualKeys() as $key => $value) {
            if (($visual[$key] ?? null) === $value) {
                unset($visual[$key]);
            }
        }

        if (!array_key_exists('nebula', $visual)) {
            $visual['nebula'] = [
                'dominant'  => '0x5a4ae0',
                'secondary' => '0x2e6ac8',
                'accent'    => '0xd85a9e',
            ];
        }
        if (!is_array($visual['post_fx'] ?? null)) {
            $visual['post_fx'] = [];
        }
        $visual['post_fx'] += [
            'bloom'             => true,
            'bloom_strength'    => 0.35,
            'bloom_threshold'   => 0.8,
            'bloom_radius'      => 0.35,
            'vignette'          => true,
            'vignette_darkness' => 0.55,
            'vignette_offset'   => 1.3,
            'vignette_blend'    => 'black',
        ];
        if (!is_array($visual['placement'] ?? null)) {
            $visual['placement'] = [];
        }
        $visual['placement'] += [
            'depth_bands' => 2,
            'light_pools' => true,
        ];

        // Material config — guarded changed + union-added.
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $from) {
                $material[$key] = $to;
            }
        }
        foreach ($materialChanges['added'] as $key => $value) {
            if (!array_key_exists($key, $material)) {
                $material[$key] = $value;
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        // Fixtures (N1) — exact-match swap of the seeded v1.0.0 list.
        if ($fixtures === self::OLD_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::NEW_FIXTURES);
        }

        // Copy — exact-match swap (the promise matrix: names what renders).
        if ((string) $row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
        }

        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];

        // Reverse exactly what up() wrote, under the same guards.

        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $to) {
                $visual[$key] = $from;
            }
        }

        foreach ($this->addedVisualKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        // Restore the superseded body flag (the pre-pass state).
        if (!array_key_exists('void_starfield', $visual)) {
            $visual['void_starfield'] = true;
        }

        if (($visual['nebula']['dominant'] ?? null) === '0x5a4ae0'
            && ($visual['nebula']['secondary'] ?? null) === '0x2e6ac8'
            && ($visual['nebula']['accent'] ?? null) === '0xd85a9e') {
            unset($visual['nebula']);
        }

        if (is_array($visual['post_fx'] ?? null)
            && ($visual['post_fx']['bloom_strength'] ?? null) === 0.35
            && ($visual['post_fx']['vignette_blend'] ?? null) === 'black') {
            unset($visual['post_fx']);
        }

        if (is_array($visual['placement'] ?? null)
            && ($visual['placement']['depth_bands'] ?? null) === 2
            && ($visual['placement']['light_pools'] ?? null) === true) {
            unset($visual['placement']);
        }

        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $to) {
                $material[$key] = $from;
            }
        }
        foreach ($materialChanges['added'] as $key => $value) {
            if (array_key_exists($key, $material) && $material[$key] === $value) {
                unset($material[$key]);
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        if ($fixtures === self::NEW_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::OLD_FIXTURES);
        }

        if ((string) $row->description === self::NEW_DESCRIPTION) {
            $update['description'] = self::OLD_DESCRIPTION;
        }

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    private function changedVisualKeys(): array
    {
        return [
            // N2: the purple ambient tinted every lit artwork canvas.
            'ambient_color'         => ['from' => '0x8844ff', 'to' => '0x7a86b8'],
            'ambient_intensity'     => ['from' => 0.2, 'to' => 0.55],
            // N6: the pool target ≈ 4.2 (void family: void 4.55, cathedral 4.0).
            'spot_intensity'        => ['from' => 0.55, 'to' => 1.2],
            // N8-adjacent: the rig is luminous, not murk.
            'tone_mapping_exposure' => ['from' => 0.6, 'to' => 0.85],
            // Fog survives 40-work scale (near/far retune).
            'fog_near'              => ['from' => 10, 'to' => 12],
            'fog_far'               => ['from' => 40, 'to' => 70],
            // N9: env_intensity 0 + environment 'none' = no HDRI, ever.
            'env_intensity'         => ['from' => 0.05, 'to' => 0],
        ];
    }

    private function addedVisualKeys(): array
    {
        return [
            'environment'            => 'none',  // the sky is procedural
            'floor_edge_fade'        => true,    // the disc dissolves into the void
            'void_depth_gradient'    => true,    // the shared zenith depth cue, reused
            'void_deepfield'         => true,    // layered band sky + current + ring
            'artwork_light_base'     => 0.5,     // the void-family standing glow
            'artwork_light_pool_cap' => 12,      // a 12-piece hang lit at once
            'hemisphere_intensity'   => 0.35,    // vertical fill for unlit far canvases
        ];
    }

    private function removedVisualKeys(): array
    {
        return [
            'void_starfield' => true,
        ];
    }

    private function materialChanges(): array
    {
        return [
            'changed' => [
                'floor_color'     => ['from' => '0x100525', 'to' => '0x0b0724'],
                'floor_roughness' => ['from' => 0.3, 'to' => 0.32],
                'floor_metalness' => ['from' => 0.5, 'to' => 0.15],
            ],
            'added' => [
                'texture_tint' => true,
            ],
        ];
    }
};

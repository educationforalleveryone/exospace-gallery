<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'crystal-cathedral';

    private const OLD_DESCRIPTION =
        'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.';
    private const NEW_DESCRIPTION =
        'A colonnade of faceted crystal piers carries pointed arches around a hall of polished dark stone; light falls from a vaulted oculus and reflects across the floor while artworks float before framed bays of stone.';

    private const OLD_VERSION = '1.0.0';
    private const NEW_VERSION = '2.0.0';

    private const OLD_TAGS = ['glass', 'crystal', 'ethereal', 'refraction'];
    private const NEW_TAGS = ['crystal', 'colonnade', 'luminous', 'ethereal'];

    private function changedValues(): array
    {
        return [
            'wall_height'           => ['from' => 12,          'to' => 13],
            'background_color'      => ['from' => '0x0a0a1a',  'to' => '0x070b14'],
            'fog_color'             => ['from' => '0x0a0a1a',  'to' => '0x070b14'],
            'fog_near'              => ['from' => 15,          'to' => 18],
            'fog_far'               => ['from' => 50,          'to' => 62],
            'ambient_color'         => ['from' => '0xddeeff',  'to' => '0xbfd4ec'],
            'ambient_intensity'     => ['from' => 0.25,        'to' => 0.34],
            'spot_intensity'        => ['from' => 0.5,         'to' => 1.15],
            'fill_intensity'        => ['from' => 0.15,        'to' => 0.22],
            'tone_mapping_exposure' => ['from' => 0.6,         'to' => 0.85],
            'colonnade_tint'        => ['from' => '0xdfeaff',  'to' => '0xe6f0fb'],
        ];
    }

    private function addedKeys(): array
    {
        return [
            'void_arcade'            => true,
            // Declared environment (was an accident of the preset's HDRI).
            'environment'            => 'studio',
            'env_intensity'          => 0.22,
            // Vertical gradient cues (the "look up" mandate).
            'hemisphere_intensity'   => 0.22,
            'void_depth_gradient'    => true,
            // Void-family artwork legibility (standing glow + pool raise).
            'artwork_light_base'     => 0.38,
            'artwork_light_pool_cap' => 12,
            // Curation opt-in: two depth rings past 12 works.
            'placement'              => ['depth_bands' => 2],
            // Declared post-processing restraint (was undeclared stock bloom).
            'post_fx'                => [
                'bloom'             => true,
                'bloom_strength'    => 0.42,
                'bloom_threshold'   => 0.82,
                'bloom_radius'      => 0.35,
                'vignette'          => true,
                'vignette_darkness' => 0.62,
                'vignette_offset'   => 1.15,
            ],
            'floor_reflection'       => 'planar',
        ];
    }

    private function removedKeys(): array
    {
        return [
            'void_colonnade' => true,
        ];
    }

    private function materialChanges(): array
    {
        return [
            'changed' => [
                'wall_color'            => ['from' => '0x202030', 'to' => '0x131a26'],
                'wall_roughness'        => ['from' => 0.2,        'to' => 0.3],
                'wall_metalness'        => ['from' => 0.0,        'to' => 0.06],
                'wall_normal_strength'  => ['from' => 0.3,        'to' => 0.25],
                'floor_color'           => ['from' => null,       'to' => '0x1a2230'],
                'floor_roughness'       => ['from' => 0.1,        'to' => 0.22],
                'floor_metalness'       => ['from' => 0.4,        'to' => 0.2],
                'floor_normal_strength' => ['from' => 0.3,        'to' => 0.25],
            ],
            'added' => [
                'floor_tile_meters' => 2.5,
                'texture_tint'      => true,
            ],
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'description', 'version', 'tags']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // 1. Guarded value replacements (seeded values only).
        foreach ($this->changedValues() as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $visual) && $visual[$key] === $from) {
                $visual[$key] = $to;
            }
        }

        // 2. Union-merge added keys (absent keys only — admin edits win).
        $visual += $this->addedKeys();

        // 3. Guarded removal of the superseded body flag.
        foreach ($this->removedKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        $material = json_decode((string) $row->material_config, true) ?: [];
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $material) && $material[$key] === $from) {
                $material[$key] = $to;
            }
        }
        $material += $materialChanges['added'];

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        // 4. Guarded copy / version / tags (admin-customized values kept).
        if ($row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
        }
        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }
        if ((array) json_decode((string) $row->tags, true) === self::OLD_TAGS) {
            $update['tags'] = json_encode(self::NEW_TAGS);
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'description', 'version', 'tags']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Reverse replacements — only where the value is still what up() wrote.
        foreach ($this->changedValues() as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $visual) && $visual[$key] === $to) {
                $visual[$key] = $from;
            }
        }

        // Remove exactly the keys up() added (only where unchanged).
        foreach ($this->addedKeys() as $key => $value) {
            if (array_key_exists($key, $visual) && $visual[$key] === $value) {
                unset($visual[$key]);
            }
        }

        // Restore the superseded body flag (the pre-pass state).
        if (!array_key_exists('void_colonnade', $visual)) {
            $visual['void_colonnade'] = true;
        }

        $material = json_decode((string) $row->material_config, true) ?: [];
        $materialChanges = $this->materialChanges();
        foreach ($materialChanges['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (array_key_exists($key, $material) && $material[$key] === $to) {
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

        if ($row->description === self::NEW_DESCRIPTION) {
            $update['description'] = self::OLD_DESCRIPTION;
        }
        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }
        if ((array) json_decode((string) $row->tags, true) === self::NEW_TAGS) {
            $update['tags'] = json_encode(self::OLD_TAGS);
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }
};

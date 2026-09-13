<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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

            $existing = json_decode((string) $row->visual_config, true) ?: [];
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                }
            }
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['visual_config' => json_encode($existing)]);

            // Restore the original description (guarded the same way).
            $old = self::OLD_DESCRIPTIONS[$slug];
            $new = self::NEW_DESCRIPTIONS[$slug];
            if ($row->description === $new) {
                DB::table('venue_templates')->where('id', $row->id)->update(['description' => $old]);
            }
        }
    }
};

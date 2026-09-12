<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'nebula-drift';

    private const OLD_VERSION = '2.0.0';
    private const NEW_VERSION = '2.1.0';

    private const OLD_DESCRIPTION =
        'A deep-field nebula surrounds the exhibition — layered cosmic masses drifting along a tilted galactic band, a slow stardrift current, and a lone meridian ring overhead. Artworks float above pools of light on a dark starlit floor.';
    private const NEW_DESCRIPTION =
        'A deep-field nebula arches over the exhibition — immense cosmic masses wheeling slowly overhead along a galactic band, a stardrift current, and a meridian ring of travelling light. Artworks float above pools of light on a floor that dissolves into the void.';

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Changed values — only while still equal to the seeded v2.0.0 value.
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

        $update = ['visual_config' => json_encode($visual)];

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
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

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

        $update = ['visual_config' => json_encode($visual)];

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
            'background_color'   => ['from' => '0x050015', 'to' => '0x000000'],
            'fog_color'          => ['from' => '0x050015', 'to' => '0x000000'],
            'ambient_intensity'  => ['from' => 0.55, 'to' => 0.62],
            'spot_intensity'     => ['from' => 1.2, 'to' => 1.35],
            'artwork_light_base' => ['from' => 0.5, 'to' => 0.62],
        ];
    }

    private function addedVisualKeys(): array
    {
        return [
            'floor_fade_span' => 1.16,
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'crystal-cathedral';

    private const OLD_VERSION = '2.0.0';
    private const NEW_VERSION = '2.1.0';

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // 1. Guarded hemisphere lift (seeded value only — admin edits win).
        if (($visual['hemisphere_intensity'] ?? null) === 0.22) {
            $visual['hemisphere_intensity'] = 0.30;
        }

        if (is_array($visual['post_fx'] ?? null) && !array_key_exists('vignette_blend', $visual['post_fx'])) {
            $visual['post_fx']['vignette_blend'] = 'black';
        }

        $update = ['visual_config' => json_encode($visual)];

        // 3. Guarded version bump.
        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'version']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Reverse exactly what up() wrote, under the same guards.
        if (($visual['hemisphere_intensity'] ?? null) === 0.30) {
            $visual['hemisphere_intensity'] = 0.22;
        }

        if (is_array($visual['post_fx'] ?? null) && ($visual['post_fx']['vignette_blend'] ?? null) === 'black') {
            unset($visual['post_fx']['vignette_blend']);
        }

        $update = ['visual_config' => json_encode($visual)];

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }
};

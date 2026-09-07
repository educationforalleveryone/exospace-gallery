<?php
/**
 * zen-propagation-check.php — §11 live template-update propagation for zen.
 *
 * Edits one obvious venue-owned value (tone_mapping_exposure 0.95 → 1.05) on
 * the zen TEMPLATE row, then proves:
 *   1. a gallery on zen receives the new value through the exporter
 *   2. a SECOND gallery on zen receives it too
 *   3. the customer's exhibition finishes survive untouched
 *   4. an unrelated venue (white-cube) is not affected
 *   5. the cache re-keys (payload changes WITHOUT any gallery touch — the
 *      venue.updated_at half of the cache key)
 *   6. rollback restores the exact prior payload
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\VenueConfigExporter;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

$failures = 0;
$check = function (string $name, bool $cond, string $detail = '') use (&$failures): void {
    if ($cond) { echo "  ✓ {$name}\n"; } else { $failures++; echo "  ✗ {$name} — {$detail}\n"; }
};

if (!DB::table('users')->count()) User::factory()->create(['plan' => 'pro']);
$zen  = DB::table('venue_templates')->where('slug', 'zen-gallery')->first();
$cube = DB::table('venue_templates')->where('slug', 'white-cube')->first();

$mk = function (string $slug, array $extra = []) use ($zen) {
    return Gallery::create(array_merge([
        'user_id'           => DB::table('users')->value('id'),
        'venue_template_id' => DB::table('venue_templates')->where('slug', $slug)->value('id'),
        'title'             => "Propagation probe ({$slug})",
        'slug'              => 'prop-'.uniqid(),
        'wall_texture'      => 'plaster',
        'floor_material'    => 'wood',
        'frame_style'       => 'black',   // a CUSTOMER choice to watch survive
        'lighting_preset'   => 'bright',
        'room_layout'       => 'square',
    ], $extra));
};

$gA = $mk('zen-gallery');
$gB = $mk('zen-gallery', ['frame_style' => 'natural']);   // customer-tuned
$gC = $mk('white-cube', ['frame_style' => 'black']);

$exporter = app(VenueConfigExporter::class);
$exp = fn (Gallery $g) => $exporter->forGallery($g->refresh())['visual_config'];

$beforeA = $exp($gA);
$beforeFrameB = $exporter->forGallery($gB->refresh())['effective_settings']['frame_style'];
$beforeCube = $exp($gC)['tone_mapping_exposure'] ?? null;

// ── 1. the venue edit ────────────────────────────────────────────────────────
DB::table('venue_templates')->where('id', $zen->id)->update([
    'visual_config' => json_encode(array_merge(json_decode($zen->visual_config, true),
        ['tone_mapping_exposure' => 1.05])),
    'updated_at'    => now(),
]);

$afterA  = $exp($gA);
$afterB  = $exp($gB);
$afterCube = $exp($gC);

$check('gallery A receives the new exposure with NO gallery-side change',
    ($afterA['tone_mapping_exposure'] ?? null) === 1.05,
    'got '.($afterA['tone_mapping_exposure'] ?? 'null'));
$check('gallery B receives the new exposure too',
    ($afterB['tone_mapping_exposure'] ?? null) === 1.05);
$check("customer B's frame lane survives the venue edit",
    $exporter->forGallery($gB->refresh())['effective_settings']['frame_style'] === $beforeFrameB,
    "frame now: ".$exporter->forGallery($gB)['effective_settings']['frame_style']);
$check('unrelated venue (white-cube) exposure untouched',
    ($afterCube['tone_mapping_exposure'] ?? null) === $beforeCube);
$check('the payload actually CHANGED for A (cache re-keyed on venue.updated_at)',
    $afterA !== $beforeA);

// ── 2. rollback ──────────────────────────────────────────────────────────────
DB::table('venue_templates')->where('id', $zen->id)->update([
    'visual_config' => $zen->visual_config,
    'updated_at'    => now(),
]);
$check('rollback restores the exact prior payload',
    $exp($gA) === $beforeA);

$gA->delete(); $gB->delete(); $gC->delete();
echo $failures === 0 ? "\n✅ ZEN §11 PROPAGATION: TEMPLATE IS LIVE CANON\n"
                     : "\n❌ {$failures} propagation failure(s)\n";
exit($failures === 0 ? 0 : 1);

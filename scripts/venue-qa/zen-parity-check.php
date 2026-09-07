<?php
/**
 * zen-parity-check.php — §14 preview↔public parity evidence for zen-gallery.
 *
 * Compares the IDENTITY payload each surface consumes:
 *   1. Public venue preview  → VenueConfigExporter::forVenuePreview()
 *   2. Gallery Live Preview  → VenueConfigExporter::forGalleryPreview()
 *   3. Public gallery        → VenueConfigExporter::forGallery()
 * (The Super Admin Venue Editor preview iframe IS the venue preview route —
 * surfaces 1 and the editor preview share one payload by construction.)
 *
 * Identity keys = venue-owned atmosphere/architecture/rig + material palette.
 * Gallery-owned exhibition finishes (wall/floor/frame/layout) are EXPECTED to
 * differ on the gallery surfaces (that's the customer's lane) — the venue
 * identity keys must be byte-identical across all three.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\VenueConfigExporter;
use App\Models\Gallery;

$venue = DB::table('venue_templates')->where('slug', 'zen-gallery')->first();
if (!$venue) { echo "FAIL: no zen-gallery row\n"; exit(1); }

// A gallery on zen with the venue's default exhibition finishes (what a new
// customer gets) AND one with custom finishes (what a tuned customer looks like).
// A gallery needs an owner; create the owner when the DB has no users yet.
if (!DB::table('users')->count()) {
    \App\Models\User::factory()->create(['plan' => 'pro']);
}
$anyUser = DB::table('users')->first();
if (!$anyUser) { echo "FAIL: no users\n"; exit(1); }

$makeGallery = fn (array $attrs) => Gallery::create(array_merge([
    'user_id'           => $anyUser->id,
    'venue_template_id' => $venue->id,
    'name'              => 'Zen parity probe',
    'title'             => 'Zen parity probe',
    'slug'              => 'zen-parity-probe-'.uniqid(),
    'wall_texture'      => 'plaster',
    'floor_material'    => 'wood',
    'frame_style'       => 'black',
    'lighting_preset'   => 'bright',
    'room_layout'       => 'square',
], $attrs));

$gDefault = $makeGallery([]);
$gCustom  = $makeGallery([
    'wall_texture' => 'white',      // customer chose a different finish family…
    'frame_style'  => 'natural',
    'room_layout'  => 'corridor',
]);

$exporter = app(VenueConfigExporter::class);

$venuePayload = $exporter->forVenuePreview(
    App\Models\VenueTemplate::query()->findOrFail($venue->id)
);
$pLive   = $exporter->forGalleryPreview($gDefault);
$pPublic = $exporter->forGallery($gDefault);
$pCustom = $exporter->forGallery($gCustom);

// The venue-owned identity: everything the runtime needs to rebuild the venue.
const IDENTITY = [
    // atmosphere
    'background_color', 'fog_color', 'fog_near', 'fog_far', 'environment', 'env_intensity',
    // architecture
    'wall_height', 'wall_depth', 'ceiling_type', 'ceiling_color', 'ceiling_height',
    'structure_pass', 'bays', 'placement', 'post_fx', 'frame_override',
    // rig
    'ambient_color', 'ambient_intensity', 'spot_intensity', 'fill_intensity',
    'hemisphere_intensity', 'tone_mapping_exposure',
    'artwork_light_base', 'artwork_light_pool_cap',
];
const MATERIAL_IDENTITY = [
    'texture_tint', 'wall_color', 'wall_roughness', 'wall_metalness', 'wall_normal_strength',
    'floor_color', 'floor_roughness', 'floor_metalness', 'floor_normal_strength', 'floor_tile_meters',
];

function pick(array $src, array $keys): array {
    $out = [];
    foreach ($keys as $k) $out[$k] = $src[$k] ?? '__ABSENT__';
    return $out;
}

$failures = 0;
$check = function (string $name, bool $cond, string $detail = '') use (&$failures): void {
    if ($cond) { echo "  ✓ {$name}\n"; } else { $failures++; echo "  ✗ {$name} — {$detail}\n"; }
};

echo "── Zen §14 payload parity (venue preview / live preview / public gallery)\n";
$venueId  = pick($venuePayload['visual_config'] ?? [], IDENTITY);
$liveId   = pick($pLive['visual_config'] ?? [], IDENTITY);
$publicId = pick($pPublic['visual_config'] ?? [], IDENTITY);
$customId = pick($pCustom['visual_config'] ?? [], IDENTITY);

$detail = function (array $a, array $b): string {
    $out = [];
    foreach ($b as $k => $v) {
        if (( $a[$k] ?? null ) !== $v) {
            $out[] = $k.': '.substr(json_encode($a[$k] ?? null), 0, 60).' !== '.substr(json_encode($v), 0, 60);
        }
    }
    return implode(' | ', $out);
};

$check('live preview identity === venue preview identity', $liveId === $venueId,
    $detail($liveId, $venueId));
$check('public gallery identity === venue preview identity', $publicId === $venueId,
    $detail($publicId, $venueId));
$check('custom-finish gallery identity === venue preview identity', $customId === $venueId,
    'customer finishes must not move venue identity');

$venueMat  = pick($venuePayload['material_config'] ?? [], MATERIAL_IDENTITY);
$liveMat   = pick($pLive['material_config'] ?? [], MATERIAL_IDENTITY);
$publicMat = pick($pPublic['material_config'] ?? [], MATERIAL_IDENTITY);
$check('live preview material identity === venue preview', $liveMat === $venueMat,
    $detail($liveMat, $venueMat));
$check('public gallery material identity === venue preview', $publicMat === $venueMat,
    $detail($publicMat, $venueMat));

// The runtime guard mirrors the shipped lists on every gallery surface.
foreach (['live' => $pLive, 'public' => $pPublic, 'custom' => $pCustom] as $n => $p) {
    $shipped = $p['venue_owned_visual'] ?? [];
    $check("{$n} surface ships the owned lists (bays+structure+environment present)",
        in_array('bays', $shipped) && in_array('structure', $shipped) && in_array('environment', $shipped));
}

// Layout clamp on the custom corridor gallery.
$check('custom corridor layout passes through', ($pCustom['effective_settings']['room_layout'] ?? null) === 'corridor',
    'layout='.($pCustom['effective_settings']['room_layout'] ?? '?'));
$check('default gallery layout resolves to square', ($pPublic['effective_settings']['room_layout'] ?? null) === 'square',
    'layout='.($pPublic['effective_settings']['room_layout'] ?? '?'));
$check('payload advertises only linear layouts', ($pPublic['supported_layouts'] ?? []) === ['square', 'corridor', 'l-shape'],
    json_encode($pPublic['supported_layouts'] ?? []));

$gDefault->delete(); $gCustom->delete();
echo $failures === 0 ? "\n✅ ZEN §14 PAYLOAD PARITY: ALL SURFACES AGREE\n"
                     : "\n❌ {$failures} parity failure(s)\n";
exit($failures === 0 ? 0 : 1);

#!/usr/bin/env php
<?php
/**
 * generate-placeholder-assets.php — generates solid-color placeholder textures
 * for every material the viewer references, so the gallery works BEFORE the
 * user runs `bash scripts/download-cc0-assets.sh`.
 *
 * Placeholders are tiny (1×1 pixel, ~2 KB) solid-color JPEGs in the correct
 * directory structure. Once the user runs `download-cc0-assets.sh`, the real
 * PBR sets (color + normal + roughness + AO at 1K resolution) overwrite these.
 *
 * Usage:
 *   php scripts/generate-placeholder-assets.php
 *
 * Run this:
 *   - After applying the refactor (before running download-cc0-assets.sh)
 *   - On a fresh deploy where assets haven't been synced yet
 *   - Whenever you add a new material to config.js TEXTURE_PATHS
 */

$base = __DIR__ . '/../public/assets/textures';

// Material → placeholder RGB colour (matches MATERIAL_PRESETS in config.js)
$materials = [
    'walls' => [
        'white'    => [0xf5, 0xf5, 0xf5],
        'concrete' => [0x8a, 0x8a, 0x8a],
        'brick'    => [0xa0, 0x82, 0x6d],
        'wood'     => [0x8b, 0x6f, 0x47],
        'plaster'  => [0xea, 0xe3, 0xd2],
        'marble'   => [0xe8, 0xe8, 0xe8],
        'velvet'   => [0x3b, 0x1f, 0x3b],
    ],
    'floors' => [
        'wood'     => [0x5c, 0x40, 0x33],
        'marble'   => [0xe8, 0xe8, 0xe8],
        'concrete' => [0x6b, 0x6b, 0x6b],
        'terrazzo' => [0xb0, 0xa8, 0x90],
        'grass'    => [0x3a, 0x6a, 0x2a],
        'sand'     => [0xc8, 0xb2, 0x7a],
        'water'    => [0x1a, 0x4a, 0x6a],
    ],
    'ceilings' => [
        'flat'   => [0xff, 0xff, 0xff],
        'beamed' => [0x8b, 0x6f, 0x47],
        'glass'  => [0xaa, 0xcc, 0xee],
    ],
];

// ── 1K JPEG placeholder via GD (no external deps required) ──────────────────
function makePlaceholderJpg(string $path, array $rgb): void {
    if (file_exists($path)) return; // don't overwrite real textures
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    // 64×64 px solid colour (small enough to be ~2 KB, big enough to not look
    // like a single pixel when tiled)
    $img = imagecreatetruecolor(64, 64);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    imagefill($img, 0, 0, $color);
    imagejpeg($img, $path, 85);
    imagedestroy($img);
}

// ── Wall + floor + ceiling PBR sets ─────────────────────────────────────────
$count = 0;
foreach ($materials as $surface => $list) {
    foreach ($list as $mat => $rgb) {
        $dir = "{$base}/{$surface}/{$mat}";

        // color.jpg (sRGB diffuse)
        makePlaceholderJpg("{$dir}/color.jpg", $rgb);
        $count++;

        // Normal map — neutral blue (0x80, 0x80, 0xff) — represents flat surface
        makePlaceholderJpg("{$dir}/normal.jpg", [0x80, 0x80, 0xff]);
        $count++;

        // Roughness — mid-grey (0.5 = neutral)
        makePlaceholderJpg("{$dir}/roughness.jpg", [0x80, 0x80, 0x80]);
        $count++;

        // AO — white (no occlusion)
        makePlaceholderJpg("{$dir}/ao.jpg", [0xff, 0xff, 0xff]);
        $count++;
    }
}

// ── Shared canvas normal map (artwork surface texture) ──────────────────────
$sharedDir = "{$base}/shared";
if (!is_dir($sharedDir)) mkdir($sharedDir, 0775, true);
if (!file_exists("{$sharedDir}/canvas_normal.jpg")) {
    $img = imagecreatetruecolor(64, 64);
    $color = imagecolorallocate($img, 0x80, 0x80, 0xff);
    imagefill($img, 0, 0, $color);
    // Add subtle noise so the normal map doesn't look completely flat
    for ($i = 0; $i < 256; $i++) {
        $x = rand(0, 63);
        $y = rand(0, 63);
        $v = 0x80 + rand(-10, 10);
        imagesetpixel($img, $x, $y, imagecolorallocate($img, $v, $v, 0xff));
    }
    imagejpeg($img, "{$sharedDir}/canvas_normal.jpg", 85);
    imagedestroy($img);
    $count++;
}

// ── Empty .gitkeep files in HDRI dir (download-cc0-assets.sh fills these) ───
$envDir = "{$base}/env";
if (!is_dir($envDir)) mkdir($envDir, 0775, true);
file_put_contents("{$envDir}/.gitkeep", "# HDRIs go here — run bash scripts/download-cc0-assets.sh\n");

// ── Venue thumbnails directory ──────────────────────────────────────────────
$thumbDir = __DIR__ . '/../public/assets/thumbnails/venues';
if (!is_dir($thumbDir)) mkdir($thumbDir, 0775, true);

$venueThumbs = [
    'white-cube'        => [0xe8, 0xe8, 0xe8],
    'infinite-void'     => [0x05, 0x05, 0x10],
    'industrial-loft'   => [0x2a, 0x28, 0x20],
    'dark-museum'       => [0x0a, 0x0a, 0x0a],
    'zen-gallery'       => [0x2a, 0x22, 0x18],
    'crystal-cathedral' => [0x1a, 0x1a, 0x3a],
    'nebula-drift'      => [0x1a, 0x05, 0x30],
    'luxury-penthouse'  => [0x0d, 0x0f, 0x18],
    'cyber-gallery'     => [0x02, 0x08, 0x20],
    'sculpture-garden'  => [0x4a, 0x8a, 0x3a],
    'mirror-lake'       => [0x20, 0x28, 0x30],
];
foreach ($venueThumbs as $slug => $rgb) {
    makePlaceholderJpg("{$thumbDir}/{$slug}.jpg", $rgb);
    $count++;
}

// ── SFX placeholder (silent 1-second MP3) ────────────────────────────────────
// Real audio files go in /assets/audio/sfx/ — the user supplies these or
// downloads from freesound.org. We create empty .gitkeep so the dir exists.
$sfxDir = __DIR__ . '/../public/assets/audio/sfx';
if (!is_dir($sfxDir)) mkdir($sfxDir, 0775, true);
file_put_contents("{$sfxDir}/.gitkeep", "# SFX files go here — footstep.mp3, interaction_click.mp3\n");

echo "────────────────────────────────────────────────────────────────\n";
echo "✅ Generated {$count} placeholder texture files.\n";
echo "\n";
echo "These are tiny 64x64 solid-colour JPGs so the gallery doesn't 404\n";
echo "before you download the real CC0 PBR sets.\n";
echo "\n";
echo "Next steps:\n";
echo "  1. bash scripts/download-cc0-assets.sh   # overwrite with real CC0 PBR sets\n";
echo "  2. php artisan preflight:assets          # verify everything's in place\n";
echo "  3. npm run build                         # build the JS bundle\n";
echo "────────────────────────────────────────────────────────────────\n";

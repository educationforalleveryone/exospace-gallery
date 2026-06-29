<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Generates an Open Graph / Twitter card image (1200×630 PNG) per gallery.
 *
 * Route: GET /gallery/{slug}/og-image
 *
 * The image is composed of:
 *   - Dark gradient background
 *   - Cover image (cropped to fill the left 50%)
 *   - Gallery title (wrapped, large)
 *   - Venue template name (small badge)
 *   - Exospace wordmark (bottom right)
 *
 * Cached for 1 hour per slug to keep CPU usage low.
 */
class OgImageController extends Controller
{
    public function show(string $slug): Response
    {
        $gallery = Cache::remember("og:gallery:{$slug}", now()->addHour(), function () use ($slug) {
            return Gallery::where('slug', $slug)
                ->with(['coverImage', 'venueTemplate', 'user'])
                ->firstOrFail();
        });

        $cacheKey = "og:image:{$slug}:v1";
        $pngBytes = Cache::remember($cacheKey, now()->addHours(6), function () use ($gallery) {
            return $this->render($gallery);
        });

        return response($pngBytes, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function render(Gallery $gallery): string
    {
        $manager = new ImageManager(new Driver());
        $canvas = $manager->create(1200, 630);

        // Background — dark gradient (top-left dark, bottom-right slightly lighter)
        $canvas->fill('#0a0a14');

        // Subtle radial highlight
        for ($r = 0; $r < 600; $r += 4) {
            $alpha = max(0, 30 - intval($r / 20));
            if ($alpha <= 0) break;
            $color = sprintf('rgba(80, 60, 140, %.2f)', $alpha / 100);
            try {
                $canvas->drawCircle(300, 315)
                    ->radius($r)
                    ->fill($color);
            } catch (\Throwable) {}
        }

        // Left half: cover image if available
        $coverUrl = $gallery->coverImage?->path
            ? public_path(ltrim($gallery->coverImage->path, '/'))
            : null;

        if ($coverUrl && file_exists($coverUrl)) {
            try {
                $cover = $manager->read($coverUrl)->cover(600, 630);
                $canvas->place($cover, 'left');
                // Add a dark gradient overlay on top of the cover for text contrast
                for ($x = 0; $x < 600; $x += 4) {
                    $alpha = intval(($x / 600) * 70);
                    $color = sprintf('rgba(10, 10, 20, %.2f)', $alpha / 100);
                    try {
                        $canvas->drawRectangle($x, 0)
                            ->size(4, 630)
                            ->fill($color);
                    } catch (\Throwable) {}
                }
            } catch (\Throwable) {
                // If cover image fails, just skip it
            }
        } else {
            // No cover — render a decorative placeholder block
            $canvas->drawRectangle(0, 0)->size(600, 630)->fill('rgba(139, 92, 246, 0.15)');
        }

        // Right half: text content
        $textX = 640;

        // Venue badge
        if ($gallery->venueTemplate) {
            $venueName = strtoupper($gallery->venueTemplate->name);
            $canvas->drawRectangle($textX, 80)
                ->size(min(strlen($venueName) * 9 + 24, 280), 32)
                ->fill('#7c3aed');
            $this->text($canvas, $venueName, $textX + 12, 88, '#ffffff', 14, 'bold');
        }

        // Gallery title — wrap at ~28 chars per line, up to 4 lines
        $title = $gallery->title ?: 'Untitled Exhibition';
        $lines = $this->wrapText($title, 28);
        $titleY = 140;
        foreach (array_slice($lines, 0, 4) as $line) {
            $this->text($canvas, $line, $textX, $titleY, '#ffffff', 38, 'bold');
            $titleY += 50;
        }

        // Description — small, muted, wrap at ~50 chars
        if ($gallery->description) {
            $desc = str_replace(["\n", "\r"], ' ', $gallery->description);
            $descLines = $this->wrapText($desc, 48);
            $descY = $titleY + 20;
            foreach (array_slice($descLines, 0, 3) as $line) {
                $this->text($canvas, $line, $textX, $descY, '#9ca3af', 16, 'normal');
                $descY += 24;
            }
        }

        // Stats row
        $statsY = 510;
        $statsText = sprintf('%d artworks · %s views',
            $gallery->images()->count(),
            number_format($gallery->view_count)
        );
        $this->text($canvas, $statsText, $textX, $statsY, '#6b7280', 14, 'normal');

        // Exospace wordmark bottom right
        $this->text($canvas, 'EXOSPACE', 1080, 590, '#6b7280', 14, 'bold');

        return $canvas->toPng()->toString();
    }

    /**
     * Draw text on the canvas using Intervention's text API.
     * Falls back gracefully if the font file isn't found.
     */
    private function text($canvas, string $text, int $x, int $y, string $color, int $size, string $weight = 'normal'): void
    {
        // Try Liberation Sans (commonly available on Linux) with bold/normal variants
        $fontBase = '/usr/share/fonts/truetype/liberation/LiberationSans';
        $fontRegular = $fontBase . '-Regular.ttf';
        $fontBold = $fontBase . '-Bold.ttf';

        $fontPath = $weight === 'bold' ? $fontBold : $fontRegular;
        if (!file_exists($fontPath)) {
            $fontPath = $weight === 'bold' ? $fontBold : $fontRegular;
        }
        if (!file_exists($fontPath)) {
            // Last resort: let Intervention pick a default
            $fontPath = null;
        }

        try {
            $drawer = $canvas->text($text)
                ->position($x, $y + $size) // baseline offset
                ->color($color)
                ->size($size);

            if ($fontPath) {
                $drawer->filename($fontPath);
            }
            $drawer->align('left');
        } catch (\Throwable $e) {
            // Text rendering is best-effort — don't crash the OG image
        }
    }

    /**
     * Naive word-wrap that splits on spaces, never breaking a word.
     */
    private function wrapText(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if (mb_strlen($current . ' ' . $word) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : $current . ' ' . $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}

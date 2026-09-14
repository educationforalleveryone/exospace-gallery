<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Geometry\Factories\RectangleFactory;

class OgImageController extends Controller
{
    private ImageManager $manager;

    public function __construct()
    {
        if (extension_loaded('imagick')) {
            $this->manager = new ImageManager(new ImagickDriver());
        } else {
            $this->manager = new ImageManager(new GdDriver());
        }
    }

    public function show(Request $request, string $slug): Response
    {
        // Loaded fresh on every request (single indexed query) so publish,
        // pin and curator edits are reflected immediately; only the rendered
        // PNG is cached, keyed by the gallery's last update to invalidate on
        // content changes instead of serving hours-old cards.
        $gallery = Gallery::where('slug', $slug)
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
            ->with(['coverImage', 'venueTemplate', 'user'])
            ->firstOrFail();

        // PIN-protected exhibitions never present publicly — no card either.
        if (! $gallery->is_active || $gallery->hasPinProtection()) {
            abort(404);
        }

        // Per-artwork OG image for deep-linked URLs
        $artworkId = $request->integer('artwork');
        $artwork = null;
        if ($artworkId) {
            $artwork = GalleryImage::with('artist')
                ->where('gallery_id', $gallery->id)
                ->where('id', $artworkId)
                ->first();
        }

        $stamp = max(
            $gallery->updated_at?->getTimestamp() ?? 0,
            $gallery->coverImage?->updated_at?->getTimestamp() ?? 0,
        );

        $cacheKey = $artwork
            ? "og:image:{$slug}:artwork:{$artworkId}:{$stamp}:v1"
            : "og:image:{$slug}:{$stamp}:v1";

        $pngBytes = Cache::flexible($cacheKey, [now()->addHours(6), now()->addHours(12)], function () use ($gallery, $artwork) {
            return $this->render($gallery, $artwork);
        });

        return response($pngBytes, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function artist(string $slug): Response
    {
        // Loaded fresh on every request (single indexed query) so profile
        // and portrait updates are reflected immediately; only the rendered
        // PNG is cached, keyed by the artist's last update to invalidate on
        // profile changes instead of serving hours-old cards.
        $artist = Artist::where('slug', $slug)
            ->with(['images' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->publiclyViewable())->orderByDesc('created_at')])
            ->firstOrFail();

        $pngBytes = Cache::flexible(
            "og:image:artist:{$slug}:{$artist->updated_at?->format('YmdHis')}:v1",
            [now()->addHours(6), now()->addHours(12)],
            fn () => $this->renderArtist($artist),
        );

        return response($pngBytes, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function renderArtist(Artist $artist): string
    {
        $canvas = $this->manager->create(1200, 630);
        $canvas->fill('#0a0a14');

        $radial = $this->getCachedRadialHighlight();
        if ($radial !== null) {
            try {
                $canvas->place($radial, 'top-left', 0, 0);
            } catch (\Throwable) {}
        }

        // Left half: portrait or latest artwork
        $imagePath = $artist->portrait_path
            ? storage_path('app/public/' . ltrim($artist->portrait_path, '/'))
            : ($artist->images->first()?->path ? public_path(ltrim($artist->images->first()->path, '/')) : null);

        if ($imagePath && file_exists($imagePath)) {
            try {
                $cover = $this->manager->read($imagePath)->cover(600, 630);
                $canvas->place($cover, 'left');
                $overlay = $this->getCachedCoverOverlay();
                if ($overlay !== null) {
                    try {
                        $canvas->place($overlay, 'top-left', 0, 0);
                    } catch (\Throwable) {}
                }
            } catch (\Throwable) {
                // Skip on unreadable image
            }
        } else {
            $canvas->drawRectangle(0, 0, function (RectangleFactory $rectangle) {
                $rectangle->size(600, 630);
                $rectangle->background('rgba(139, 92, 246, 0.15)');
            });
            // Large initials placeholder
            $this->text($canvas, $artist->initials, 240, 280, '#7c3aed', 96, 'bold');
        }

        $textX = 640;

        // "ARTIST" badge
        $this->text($canvas, 'ARTIST', $textX, 80, '#6b7280', 12, 'bold');

        // Artist name — wrapped, up to 4 lines
        $lines = $this->wrapText($artist->name ?: 'Artist', 24);
        $nameY = 120;
        foreach (array_slice($lines, 0, 4) as $line) {
            $this->text($canvas, $line, $textX, $nameY, '#ffffff', 42, 'bold');
            $nameY += 54;
        }

        // Factual stats from real data
        $workCount = $artist->images->count();
        $statsY = $nameY + 16;
        if ($workCount > 0) {
            $this->text($canvas, sprintf('%d artworks on display', $workCount), $textX, $statsY, '#a78bfa', 18, 'normal');
            $statsY += 30;
        }
        if ($artist->location) {
            $this->text($canvas, $artist->location, $textX, $statsY, '#9ca3af', 16, 'normal');
            $statsY += 26;
        }
        if ($artist->bio) {
            $bio = str_replace(["\n", "\r"], ' ', $artist->bio);
            $bioLines = $this->wrapText($bio, 46);
            foreach (array_slice($bioLines, 0, 4) as $line) {
                $this->text($canvas, $line, $textX, $statsY + 10, '#9ca3af', 15, 'normal');
                $statsY += 22;
            }
        }

        $this->text($canvas, 'EXOSPACE', 1080, 590, '#6b7280', 14, 'bold');

        return $canvas->toPng()->toString();
    }

    private function render(Gallery $gallery, ?GalleryImage $artwork = null): string
    {
        $canvas = $this->manager->create(1200, 630);

        // Background — dark base color
        $canvas->fill('#0a0a14');

        $radial = $this->getCachedRadialHighlight();
        if ($radial !== null) {
            try {
                $canvas->place($radial, 'top-left', 0, 0);
            } catch (\Throwable) {
                // If compositing fails, skip — the canvas is already filled.
            }
        }

        // Left half: artwork image (if deep-linked) or cover image
        $imagePath = $artwork?->path ?? $gallery->coverImage?->path;
        $coverUrl = $imagePath ? public_path(ltrim($imagePath, '/')) : null;

        if ($coverUrl && file_exists($coverUrl)) {
            try {
                $cover = $this->manager->read($coverUrl)->cover(600, 630);
                $canvas->place($cover, 'left');
                $overlay = $this->getCachedCoverOverlay();
                if ($overlay !== null) {
                    try {
                        $canvas->place($overlay, 'top-left', 0, 0);
                    } catch (\Throwable) {}
                }
            } catch (\Throwable) {
                // If cover image fails, just skip it
            }
        } else {
            $canvas->drawRectangle(0, 0, function (RectangleFactory $rectangle) {
                $rectangle->size(600, 630);
                $rectangle->background('rgba(139, 92, 246, 0.15)');
            });
        }

        // Right half: text content
        $textX = 640;

        if ($artwork) {
            // "FROM" label
            $this->text($canvas, 'FROM', $textX, 80, '#6b7280', 12, 'bold');
            $this->text($canvas, $gallery->title ?: 'Untitled Exhibition', $textX, 100, '#9ca3af', 16, 'normal');

            // Artwork title
            $title = $artwork->title ?: $artwork->original_name ?: 'Untitled';
            $lines = $this->wrapText($title, 28);
            $titleY = 150;
            foreach (array_slice($lines, 0, 4) as $line) {
                $this->text($canvas, $line, $textX, $titleY, '#ffffff', 38, 'bold');
                $titleY += 50;
            }

            // Artist name
            if ($artwork->artist) {
                $this->text($canvas, 'by ' . $artwork->artist->name, $textX, $titleY + 10, '#a78bfa', 18, 'normal');
            }

            // Artwork description
            if ($artwork->description) {
                $desc = str_replace(["\n", "\r"], ' ', $artwork->description);
                $descLines = $this->wrapText($desc, 48);
                $descY = $titleY + 50;
                foreach (array_slice($descLines, 0, 3) as $line) {
                    $this->text($canvas, $line, $textX, $descY, '#9ca3af', 16, 'normal');
                    $descY += 24;
                }
            }
        } else {
            // Gallery-level OG image (original behavior)

            // Venue badge
            if ($gallery->venueTemplate) {
                $venueName = strtoupper($gallery->venueTemplate->name);
                $canvas->drawRectangle($textX, 80, function (RectangleFactory $rectangle) use ($venueName) {
                    $rectangle->size(min(strlen($venueName) * 9 + 24, 280), 32);
                    $rectangle->background('#7c3aed');
                });
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
        }

        // Exospace wordmark bottom right
        $this->text($canvas, 'EXOSPACE', 1080, 590, '#6b7280', 14, 'bold');

        return $canvas->toPng()->toString();
    }

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

    private function getCachedRadialHighlight(): ?\Intervention\Image\Image
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $w = 600;
            $h = 630;
            $cx = 300;
            $cy = 315;
            $maxR = 350.0;

            $img = $this->manager->create($w, $h);
            // Start fully transparent (rgba 0,0,0,0).
            $img->fill('rgba(0, 0, 0, 0)');

            for ($y = 0; $y < $h; $y += 4) {
                for ($x = 0; $x < $w; $x += 4) {
                    $dx = $x - $cx;
                    $dy = $y - $cy;
                    $dist = sqrt($dx * $dx + $dy * $dy);
                    $alpha = max(0, 0.30 * (1.0 - $dist / $maxR));
                    if ($alpha <= 0.001) {
                        continue;
                    }
                    $color = sprintf('rgba(80, 60, 140, %.3f)', $alpha);
                    try {
                        $img->drawRectangle($x, $y, function (RectangleFactory $rectangle) use ($color) {
                            $rectangle->size(4, 4);
                            $rectangle->background($color);
                        });
                    } catch (\Throwable) {
                        // Skip on driver error
                    }
                }
            }

            $cached = $img;
            return $cached;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getCachedCoverOverlay(): ?\Intervention\Image\Image
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $w = 600;
            $h = 630;
            $img = $this->manager->create($w, $h);
            $img->fill('rgba(0, 0, 0, 0)');

            for ($x = 0; $x < $w; $x += 4) {
                $alpha = 0.70 * ($x / $w);
                if ($alpha <= 0.001) {
                    continue;
                }
                $color = sprintf('rgba(10, 10, 20, %.3f)', $alpha);
                try {
                    $img->drawRectangle($x, 0, function (RectangleFactory $rectangle) use ($color, $h) {
                        $rectangle->size(4, $h);
                        $rectangle->background($color);
                    });
                } catch (\Throwable) {
                    // Skip on driver error
                }
            }

            $cached = $img;
            return $cached;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
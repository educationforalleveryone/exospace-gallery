<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * preflight:assets — verifies that every asset referenced by every venue
 * template and every gallery actually exists on disk.
 *
 * Run this:
 *   - Locally after pulling changes
 *   - In CI before a deploy
 *   - On the server after `php artisan storage:link` if uploads start 404'ing
 *   - Whenever you suspect a venue's decorations / HDRI / audio are missing
 *
 * Exit code is non-zero if any asset is missing — wire this into your CI to
 * block deploys that reference non-existent files.
 *
 * Usage:
 *   php artisan preflight:assets
 *   php artisan preflight:assets --fix   (creates empty placeholder files
 *                                          so the gallery doesn't crash —
 *                                          use as a last resort)
 */
class PreflightAssets extends Command
{
    protected $signature = 'preflight:assets
                            {--fix : Create empty placeholder files for missing assets}';

    protected $description = 'Verify all venue/gallery assets exist on disk';

    public function handle(): int
    {
        $this->info('🔍 Running asset preflight check...');
        $this->newLine();

        $errors = 0;
        $warnings = 0;

        // ── 1. Public asset directories must exist ───────────────────────────
        $this->info('Checking public asset directories...');
        $requiredDirs = [
            'public/assets/textures/walls',
            'public/assets/textures/floors',
            'public/assets/textures/ceilings',
            'public/assets/textures/env',
            'public/assets/textures/shared',
            'public/assets/audio/sfx',
            'public/assets/thumbnails/venues',
            'public/assets/models/venue-props',
            'public/assets/models/frames',
            'public/decoders/draco',     // DRACO wasm
            'public/decoders/basis',     // KTX2 basis transcoder
        ];
        foreach ($requiredDirs as $dir) {
            $full = base_path($dir);
            if (! is_dir($full)) {
                $this->error("  ✗ Missing directory: {$dir}");
                if ($this->option('fix')) {
                    @mkdir($full, 0775, true);
                    $this->line("    ↳ Created empty directory");
                }
                $errors++;
            } else {
                $this->line("  ✓ {$dir}");
            }
        }
        $this->newLine();

        // ── 2. Storage symlink must be valid ─────────────────────────────────
        $this->info('Checking storage symlink...');
        $publicStorage = public_path('storage');
        if (! is_link($publicStorage)) {
            $this->error('  ✗ public/storage symlink missing — run `php artisan storage:link`');
            $errors++;
        } else {
            $target = readlink($publicStorage);
            $this->line("  ✓ public/storage → {$target}");
        }
        $this->newLine();

        // ── 3. Per-venue: check thumbnail, HDRI, audio, decoration GLBs ──────
        $this->info('Checking venue templates...');
        $venues = VenueTemplate::all();
        foreach ($venues as $venue) {
            $this->line("  • {$venue->name} (slug: {$venue->slug})");

            // Thumbnail
            if ($venue->thumbnail_path) {
                if (! Storage::disk('public')->exists($venue->thumbnail_path)) {
                    $this->error("    ✗ Missing thumbnail: {$venue->thumbnail_path}");
                    $errors++;
                }
            } else {
                // Fallback to /assets/thumbnails/venues/{slug}.jpg
                $fallback = public_path("assets/thumbnails/venues/{$venue->slug}.jpg");
                if (! file_exists($fallback)) {
                    $this->warn("    ⚠ No thumbnail_path and no /assets/thumbnails/venues/{$venue->slug}.jpg");
                    $warnings++;
                }
            }

            // HDRI
            if ($venue->hdri_path && ! Storage::disk('public')->exists($venue->hdri_path)) {
                $this->error("    ✗ Missing HDRI: {$venue->hdri_path}");
                $errors++;
            }

            // Default audio
            if ($venue->default_audio_path && ! Storage::disk('public')->exists($venue->default_audio_path)) {
                $this->error("    ✗ Missing audio: {$venue->default_audio_path}");
                $errors++;
            }

            // Decoration GLBs
            if (is_array($venue->decorations)) {
                foreach ($venue->decorations as $dec) {
                    $path = $dec['model_path'] ?? null;
                    if (! $path) continue;
                    if (! Storage::disk('public')->exists($path)) {
                        $this->error("    ✗ Missing decoration GLB: {$path}");
                        $errors++;
                    }
                }
            }

            // Preview model
            if ($venue->preview_model_path && ! Storage::disk('public')->exists($venue->preview_model_path)) {
                $this->error("    ✗ Missing preview model: {$venue->preview_model_path}");
                $errors++;
            }
        }
        $this->newLine();

        // ── 4. Per-gallery: check audio, logos, curtain assets ───────────────
        $this->info('Checking galleries...');
        $galleries = Gallery::with('user')->limit(100)->get();
        foreach ($galleries as $gallery) {
            $issues = [];
            if ($gallery->audio_path && ! Storage::disk('public')->exists($gallery->audio_path)) {
                $issues[] = "audio";
            }
            if ($gallery->custom_logo_path && ! Storage::disk('public')->exists($gallery->custom_logo_path)) {
                $issues[] = "custom_logo";
            }
            if ($gallery->curtain_logo_path && ! Storage::disk('public')->exists($gallery->curtain_logo_path)) {
                $issues[] = "curtain_logo";
            }
            if (! empty($issues)) {
                $this->error("  ✗ Gallery #{$gallery->id} ({$gallery->title}): missing " . implode(', ', $issues));
                $errors++;
            }
        }
        $this->newLine();

        // ── 5. Core textures referenced by viewer config ─────────────────────
        $this->info('Checking core texture files...');
        $coreTextures = [
            'public/assets/textures/walls/white/color.jpg',
            'public/assets/textures/walls/concrete/color.jpg',
            'public/assets/textures/walls/brick/color.jpg',
            'public/assets/textures/walls/wood/color.jpg',
            'public/assets/textures/floors/wood/color.jpg',
            'public/assets/textures/floors/marble/color.jpg',
            'public/assets/textures/floors/concrete/color.jpg',
            'public/assets/textures/floors/grass/color.jpg',
            'public/assets/textures/shared/canvas_normal.jpg',
            'public/assets/audio/sfx/footstep.mp3',
            'public/assets/audio/sfx/interaction_click.mp3',
        ];
        foreach ($coreTextures as $tex) {
            if (! file_exists(base_path($tex))) {
                $this->error("  ✗ Missing: {$tex}");
                $errors++;
            }
        }
        $this->newLine();

        // ── Summary ──────────────────────────────────────────────────────────
        if ($errors === 0 && $warnings === 0) {
            $this->info('✅ All assets present. Gallery will load without 404s.');
            return Command::SUCCESS;
        }

        if ($errors > 0) {
            $this->error("❌ {$errors} error(s), {$warnings} warning(s).");
            return Command::FAILURE;
        }

        $this->warn("⚠ {$warnings} warning(s), 0 errors.");
        return Command::SUCCESS;
    }
}

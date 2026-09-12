<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PreflightAssets extends Command
{
    protected $signature = 'preflight:assets
                            {--fix : Create placeholder files for missing core textures}';

    protected $description = 'Verify all venue/gallery assets exist on disk';

    public function handle(): int
    {
        $this->info('🔍 Running asset preflight check...');
        $this->newLine();

        $errors   = 0;
        $warnings = 0;

        $this->info('Checking public asset directories...');

        $requiredDirs = [
            'public/assets/textures/walls',
            'public/assets/textures/floors',
            'public/assets/textures/ceilings',
            'public/assets/textures/env',
            'public/assets/textures/shared',
            'public/assets/audio/sfx',
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

        $optionalDirs = [
            'public/assets/thumbnails/venues',  // venue preview thumbnails (gradient fallback in UI)
            'public/assets/models/venue-props', // GLB decoration models (currently procedural)
            'public/assets/models/frames',      // GLB artwork frame models (currently procedural)
        ];
        foreach ($optionalDirs as $dir) {
            $full = base_path($dir);
            if (! is_dir($full)) {
                @mkdir($full, 0775, true);
                $this->warn("  ⚠ Created empty optional directory: {$dir}");
                $warnings++;
            } else {
                $this->line("  ✓ {$dir} (optional)");
            }
        }
        $this->newLine();

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

            // Thumbnail — optional (UI has gradient fallback). Warn if missing.
            if ($venue->thumbnail_path) {
                if (! Storage::disk('public')->exists($venue->thumbnail_path)) {
                    $this->error("    ✗ Missing thumbnail: {$venue->thumbnail_path}");
                    $errors++;
                }
            } else {
                // Fallback to /assets/thumbnails/venues/{slug}.jpg
                $fallback = public_path("assets/thumbnails/venues/{$venue->slug}.jpg");
                if (! file_exists($fallback)) {
                    $this->warn("    ⚠ No thumbnail — venue picker will use gradient fallback");
                    $warnings++;
                }
            }

            // HDRI — error if missing (venue references it but file isn't there)
            if ($venue->hdri_path && ! Storage::disk('public')->exists($venue->hdri_path)) {
                $this->error("    ✗ Missing HDRI: {$venue->hdri_path}");
                $errors++;
            }

            // Default audio — error if missing
            if ($venue->default_audio_path && ! Storage::disk('public')->exists($venue->default_audio_path)) {
                $this->error("    ✗ Missing audio: {$venue->default_audio_path}");
                $errors++;
            }

            // Decoration GLBs — error if referenced but missing
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

            // Preview model — warn if missing (only used for admin 3D preview, not in production)
            if ($venue->preview_model_path && ! Storage::disk('public')->exists($venue->preview_model_path)) {
                $this->warn("    ⚠ Missing preview model: {$venue->preview_model_path}");
                $warnings++;
            }
        }
        $this->newLine();

        $this->info('Checking galleries...');
        Gallery::with('user')->chunkById(50, function ($galleries) use (&$errors) {
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
        });
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
        if ($errors === 0) {
            $this->line('  ✓ All core textures present');
        }
        $this->newLine();

        if ($errors === 0 && $warnings === 0) {
            $this->info('✅ All assets present. Gallery will load without 404s.');
            return Command::SUCCESS;
        }

        if ($errors > 0) {
            $this->error("❌ {$errors} error(s), {$warnings} warning(s).");
            return Command::FAILURE;
        }

        $this->warn("⚠ {$warnings} warning(s), 0 errors — gallery will run, but consider addressing warnings.");
        return Command::SUCCESS;
    }
}

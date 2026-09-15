<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RegenerateImageMedia;
use App\Models\GalleryImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReconcileArtworkMedia extends Command
{
    protected $signature = 'exospace:reconcile-artwork-media
                            {--fix : Dispatch regeneration jobs (without this flag the command only reports)}
                            {--limit=100 : Maximum number of images to process per run}';

    protected $description = 'Find artworks without Spatie media records and re-register media from their legacy files (dry-run report by default).';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $limit = max(1, (int) $this->option('limit'));

        $missingMedia = GalleryImage::query()
            ->whereDoesntHave('media', fn ($q) => $q->where('collection_name', 'original'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($missingMedia->isEmpty()) {
            $this->info('All artworks have media records. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info("Artworks without media: {$missingMedia->count()}");

        $dispatched = 0;
        $unrecoverable = 0;

        foreach ($missingMedia as $image) {
            $relativePath = Str::after($image->path, 'storage/');

            if (! Storage::disk('public')->exists($relativePath)) {
                $unrecoverable++;
                $this->warn("  [missing file] image {$image->id}: {$relativePath}");
                continue;
            }

            if ($fix) {
                Queue::push(new RegenerateImageMedia($image->id));
                $dispatched++;
            } else {
                $this->line("  [would fix] image {$image->id}: {$relativePath}");
            }
        }

        if ($fix) {
            $this->info("Dispatched {$dispatched} regeneration job(s).");
        } else {
            $this->info('Dry run — re-run with --fix to dispatch regeneration jobs.');
        }

        if ($unrecoverable > 0) {
            Log::warning('ReconcileArtworkMedia: artworks whose legacy file is gone', [
                'count'      => $unrecoverable,
                'image_ids'  => $missingMedia
                    ->filter(fn (GalleryImage $i) => ! Storage::disk('public')->exists(Str::after($i->path, 'storage/')))
                    ->pluck('id')
                    ->all(),
            ]);
        }

        return self::SUCCESS;
    }
}

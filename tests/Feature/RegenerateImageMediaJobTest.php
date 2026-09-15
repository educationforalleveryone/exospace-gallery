<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegenerateImageMedia;
use App\Models\GalleryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class RegenerateImageMediaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // MediaLibrary keeps an "original" file on the fake disk across tests
        // in the same process — Storage::fake is rebuilt per test, so no
        // explicit cleanup is needed here.

        parent::tearDown();
    }

    private function makeImage(array $overrides = []): GalleryImage
    {
        return GalleryImage::factory()->create(array_merge([
            'filename' => 'art-'.uniqid().'.jpg',
            'path'     => 'storage/galleries/1/art-'.uniqid().'.jpg',
        ], $overrides));
    }

    // ── idempotency / duplicate safety ───────────────────────────────────

    public function test_job_registers_media_from_the_source_file(): void
    {
        Storage::fake('public');
        $image = $this->makeImage();

        $relativePath = str($image->path)->after('storage/')->toString();
        Storage::disk('public')->put($relativePath, UploadedFile::fake()->image('art.jpg', 100, 80)->getContent());

        (new RegenerateImageMedia($image->id))->handle();

        $this->assertSame(
            1,
            Media::where('model_type', GalleryImage::class)->where('model_id', $image->id)->count(),
            'The original media record should be registered exactly once.'
        );
    }

    public function test_duplicate_execution_does_not_create_a_second_media_record(): void
    {
        Storage::fake('public');
        $image = $this->makeImage();

        $relativePath = str($image->path)->after('storage/')->toString();
        Storage::disk('public')->put($relativePath, UploadedFile::fake()->image('art.jpg', 100, 80)->getContent());

        (new RegenerateImageMedia($image->id))->handle();
        (new RegenerateImageMedia($image->id))->handle();

        $this->assertSame(
            1,
            Media::where('model_type', GalleryImage::class)->where('model_id', $image->id)->count(),
            'The has-media guard must make re-execution a no-op (at-least-once delivery).'
        );
    }

    // ── deleted / missing records must not poison failed_jobs ────────────

    public function test_deleted_image_is_skipped_without_failing(): void
    {
        Storage::fake('public');
        $image = $this->makeImage();
        $imageId = $image->id;
        $image->delete();

        (new RegenerateImageMedia($imageId))->handle();

        $this->assertSame(0, Media::count());
    }

    public function test_missing_source_file_fails_loudly_for_the_failed_job_ledger(): void
    {
        Storage::fake('public');
        $image = $this->makeImage();
        // The path column points to a file that does not exist on the disk.

        try {
            (new RegenerateImageMedia($image->id))->handle();
            $this->fail('A missing source file is a data-integrity problem and must surface as a failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('file not found on disk', $e->getMessage());
        }

        $this->assertSame(0, Media::count());
    }
}

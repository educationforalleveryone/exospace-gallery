<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-4 regression tests: GDPR right-to-erasure for Spatie media originals.
 *
 * Before the fix, UserDeletionService::deleteGalleryFiles() only deleted
 * the legacy `path` column file — it did NOT clear the Spatie Media Library
 * 'original' collection. The Spatie originals (which contained unstripped
 * EXIF/GPS data from the raw upload) persisted on disk forever, even after
 * the user deleted their account. This is a GDPR Art. 17 violation.
 *
 * Additionally, ImageProcessingService::registerMedia() passed the raw
 * UploadedFile to Spatie's addMedia(), storing the original with full
 * EXIF metadata. The fix re-encodes the image as JPEG (stripping EXIF)
 * before adding it to Spatie.
 */
class UserDeletionGdprTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * When a user is deleted, every image's Spatie media files must be
     * removed from disk — not just the legacy `path` column file.
     */
    public function test_user_deletion_clears_spatie_media_originals(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        // Create an image with Spatie media
        $image = GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'filename'   => 'test-image.jpg',
            'path'       => 'storage/galleries/' . $gallery->id . '/test-image.jpg',
        ]);

        // Add a file to the Spatie 'original' collection
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
        $image->addMedia($file)->toMediaCollection('original');

        // Verify the Spatie media exists
        $this->assertTrue($image->hasMedia('original'));

        // Get the Spatie media file path on disk
        $media = $image->getFirstMedia('original');
        $this->assertNotNull($media);
        $mediaPath = $media->getPath();
        $this->assertFileExists($mediaPath);

        // Delete the user
        app(UserDeletionService::class)->deleteUser($user, 'Test: GDPR deletion');

        // The Spatie media file must be deleted from disk
        $this->assertFileDoesNotExist($mediaPath);

        // The Spatie media DB record must be deleted
        $this->assertDatabaseMissing('media', [
            'model_id'   => $image->id,
            'model_type' => GalleryImage::class,
        ]);

        // The user must be deleted
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * When a user is deleted, the legacy `path` column file must also be
     * deleted (existing behavior — verify no regression).
     */
    public function test_user_deletion_still_deletes_legacy_path_files(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $legacyPath = 'galleries/' . $gallery->id . '/legacy-image.jpg';
        Storage::disk('public')->put($legacyPath, 'fake-image-bytes');

        GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'filename'   => 'legacy-image.jpg',
            'path'       => 'storage/' . $legacyPath,
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'Test: legacy path deletion');

        $this->assertFalse(
            Storage::disk('public')->exists($legacyPath),
            'Legacy path file was not deleted.'
        );
    }

    /**
     * When a user is deleted, audio/logo/curtain files must also be deleted.
     */
    public function test_user_deletion_deletes_studio_branding_files(): void
    {
        $user = User::factory()->studio()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $logoPath = 'logos/logo-' . uniqid() . '.png';
        $curtainPath = 'logos/curtain-' . uniqid() . '.png';
        $audioPath = 'audio/audio-' . uniqid() . '.mp3';

        Storage::disk('public')->put($logoPath, 'fake-logo');
        Storage::disk('public')->put($curtainPath, 'fake-curtain');
        Storage::disk('public')->put($audioPath, 'fake-audio');

        $gallery->forceFill([
            'custom_logo_path'  => 'storage/' . $logoPath,
            'curtain_logo_path' => 'storage/' . $curtainPath,
            'audio_path'        => 'storage/' . $audioPath,
        ])->save();

        // Mock CoolifyDomainManager to avoid HTTP calls during downgrade
        $mock = \Mockery::mock(\App\Services\CoolifyDomainManager::class);
        $mock->shouldReceive('removeDomain')->andReturn(['success' => true]);
        $this->app->instance(\App\Services\CoolifyDomainManager::class, $mock);

        app(UserDeletionService::class)->deleteUser($user, 'Test: branding file deletion');

        $this->assertFalse(Storage::disk('public')->exists($logoPath));
        $this->assertFalse(Storage::disk('public')->exists($curtainPath));
        $this->assertFalse(Storage::disk('public')->exists($audioPath));
    }

    /**
     * ImageProcessingService::registerMedia() must store the EXIF-stripped
     * re-encoded image, NOT the raw upload. Verify by checking that the
     * Spatie original file size differs from the raw upload size (the
     * re-encoded JPEG is typically smaller and has no EXIF block).
     */
    public function test_register_media_stores_exif_stripped_image_not_raw_upload(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        // Create a fake JPEG with some "EXIF-like" data
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        // Process the image (creates the EXIF-stripped main JPEG on disk)
        $imageService = app(\App\Services\ImageProcessingService::class);
        $data = $imageService->process($file, $gallery->id);

        // Create the GalleryImage row
        $image = $gallery->images()->create([
            'filename'       => $data['filename'],
            'original_name'  => 'photo.jpg',
            'path'           => $data['path'],
            'mime_type'      => $data['mime_type'],
            'size'           => $data['size'],
            'width'          => $data['width'],
            'height'         => $data['height'],
            'orientation'    => 'landscape',
            'position_order' => 0,
        ]);

        // Register with Spatie
        $imageService->registerMedia($image, $file);

        // The Spatie original should exist
        $this->assertTrue($image->hasMedia('original'));

        $media = $image->getFirstMedia('original');
        $this->assertNotNull($media);

        // The Spatie original file should be the re-encoded JPEG (same size
        // as the main image saved by process()), NOT the raw upload.
        $mainPath = Storage::disk('public')->path("galleries/{$gallery->id}/{$image->filename}");
        $mainSize = filesize($mainPath);
        $spatieSize = filesize($media->getPath());

        // The Spatie original should match the EXIF-stripped main image size
        // (within a small tolerance for filesystem block size differences).
        $this->assertEquals(
            $mainSize,
            $spatieSize,
            'Spatie original does not match the EXIF-stripped main image — registerMedia may be storing the raw upload instead.'
        );

        // The Spatie original should NOT be the same size as the raw upload
        // (the raw upload has EXIF data; the re-encoded JPEG does not).
        // Note: this assertion may be fragile if the raw upload happens to
        // be the same size — but in practice, re-encoding changes the size.
        $rawSize = filesize($file->getRealPath());
        $this->assertNotEquals(
            $rawSize,
            $spatieSize,
            'Spatie original is the same size as the raw upload — EXIF data may not have been stripped.'
        );
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}

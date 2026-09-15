<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegenerateImageMedia;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Observers\SitemapCacheObserver;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsSchedule;
use Tests\TestCase;

class StorageReliabilityTest extends TestCase
{
    use RefreshDatabase;
    use AssertsSchedule;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->forceDeleteDir($dir);
        }

        // Model events registered inside tests leak into later tests in the
        // same process — restore the production observer set.
        Artist::flushEventListeners();
        Artist::observe(SitemapCacheObserver::class);
        GalleryImage::flushEventListeners();
        GalleryImage::observe(SitemapCacheObserver::class);
        Gallery::flushEventListeners();
        Gallery::observe(SitemapCacheObserver::class);
        VenueTemplate::flushEventListeners();
        VenueTemplate::observe(SitemapCacheObserver::class);

        parent::tearDown();
    }

    private function forceDeleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        @chmod($dir, 0755);

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->forceDeleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/exospace-storage-test-' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function pointPublicDiskAt(string $dir): void
    {
        config(['filesystems.disks.public.root' => $dir]);
        Storage::forgetDisk('public');
    }

    private function superAdmin(): User
    {
        return User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);
    }

    private function venuePayload(array $files = []): array
    {
        return array_merge([
            'name'             => 'Storage Probe Venue',
            'description'      => 'Uploaded through storage reliability tests.',
            'category'         => 'gallery',
            'plan_required'    => 'free',
            'capacity_min'     => 10,
            'is_active'        => true,
            'default_settings' => json_encode([
                'wall_texture'    => 'white',
                'floor_material'  => 'wood',
                'frame_style'     => 'modern',
                'lighting_preset' => 'bright',
                'room_layout'     => 'square',
            ]),
        ], $files);
    }

    // ── A. Storage write failures are surfaced, never silent ────────────

    public function test_artwork_upload_reports_failure_when_public_disk_is_unwritable(): void
    {
        $dir = $this->makeTempDir();
        $this->pointPublicDiskAt($dir);

        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        chmod($dir, 0555);

        $response = $this->actingAs($owner)
            ->postJson(route('admin.images.store', $gallery), [
                'file' => UploadedFile::fake()->image('piece.jpg', 400, 300),
            ]);

        chmod($dir, 0755);

        $response->assertStatus(500);
        $response->assertJsonMissingExact(['success' => true]);
        $this->assertSame(0, GalleryImage::count());
        $this->assertSame(0, \Spatie\MediaLibrary\MediaCollections\Models\Media::count());
    }

    public function test_invoice_pdf_failure_does_not_point_at_missing_bytes(): void
    {
        $dir = $this->makeTempDir();
        config(['filesystems.disks.local.root' => $dir]);
        Storage::forgetDisk('local');

        chmod($dir, 0555);

        $user = User::factory()->create();
        $transaction = \App\Models\Transaction::factory()->create(['user_id' => $user->id]);

        $invoice = app(\App\Services\InvoiceGenerator::class)->generateForTransaction($transaction, $user);

        chmod($dir, 0755);

        $this->assertNull($invoice, 'Invoice generation must fail loudly instead of storing a path that was never written.');
        $this->assertFalse(
            \App\Models\Invoice::whereNotNull('pdf_path')->exists(),
            'No invoice may point at a PDF that was never written.',
        );
    }

    public function test_disks_carrying_user_data_throw_on_write_failure(): void
    {
        $this->assertTrue((bool) config('filesystems.disks.public.throw'), 'public disk must surface write failures');
        $this->assertTrue((bool) config('filesystems.disks.local.throw'), 'local disk must surface write failures');
    }

    public function test_public_disk_url_and_link_configuration_match_production_layout(): void
    {
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame(
            [public_path('storage') => storage_path('app/public')],
            config('filesystems.links'),
        );
    }

    // ── B. Replacement protects the working asset ───────────────────────

    public function test_artist_portrait_survives_a_failed_replacement(): void
    {
        Storage::fake('public');
        $curator = User::factory()->create();
        $artist = Artist::factory()->create([
            'created_by'    => $curator->id,
            'portrait_path' => 'artist-portraits/original.png',
        ]);
        Storage::disk('public')->put('artist-portraits/original.png', 'original-bytes');

        Artist::updating(function () {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->actingAs($curator)
                ->put(route('admin.artists.update', $artist), [
                    'name' => $artist->name,
                    'portrait' => UploadedFile::fake()->image('replacement.png'),
                ]);
        } finally {
            Artist::flushEventListeners();
            Artist::observe(SitemapCacheObserver::class);
        }

        $this->assertDatabaseHas('artists', ['id' => $artist->id, 'portrait_path' => 'artist-portraits/original.png']);
        Storage::disk('public')->assertExists('artist-portraits/original.png');

        $leftovers = array_values(array_filter(
            Storage::disk('public')->allFiles('artist-portraits'),
            fn ($p) => $p !== 'artist-portraits/original.png',
        ));
        $this->assertSame([], $leftovers, 'The uncommitted replacement file must be cleaned up.');
    }

    public function test_artist_portrait_replacement_deletes_old_file_only_after_commit(): void
    {
        Storage::fake('public');
        $curator = User::factory()->create();
        $artist = Artist::factory()->create([
            'created_by'    => $curator->id,
            'portrait_path' => 'artist-portraits/old.png',
        ]);
        Storage::disk('public')->put('artist-portraits/old.png', 'old-bytes');

        $this->actingAs($curator)
            ->followingRedirects()
            ->put(route('admin.artists.update', $artist), [
                'name' => $artist->name,
                'portrait' => UploadedFile::fake()->image('new.png'),
            ])
            ->assertOk();

        $this->assertDatabaseHas('artists', ['id' => $artist->id]);
        Storage::disk('public')->assertMissing('artist-portraits/old.png');
        $this->assertSame(
            1,
            count(Storage::disk('public')->allFiles('artist-portraits')),
            'Exactly one portrait must remain after a successful replacement.',
        );
    }

    public function test_artist_deletion_removes_portrait_after_row_is_gone(): void
    {
        Storage::fake('public');
        $curator = User::factory()->create();
        $artist = Artist::factory()->create([
            'created_by'    => $curator->id,
            'portrait_path' => 'artist-portraits/doomed.png',
        ]);
        Storage::disk('public')->put('artist-portraits/doomed.png', 'bytes');

        Artist::deleting(function () {
            throw new \RuntimeException('Simulated deletion failure');
        });

        try {
            $this->actingAs($curator)->delete(route('admin.artists.destroy', $artist));
        } finally {
            Artist::flushEventListeners();
            Artist::observe(SitemapCacheObserver::class);
        }

        $this->assertDatabaseHas('artists', ['id' => $artist->id]);
        $this->assertTrue(Storage::disk('public')->exists('artist-portraits/doomed.png'), 'A failed artist delete must not destroy the portrait.');

        Artist::flushEventListeners();
        Artist::observe(SitemapCacheObserver::class);

        $this->actingAs($curator)->delete(route('admin.artists.destroy', $artist));
        $this->assertDatabaseMissing('artists', ['id' => $artist->id]);
        Storage::disk('public')->assertMissing('artist-portraits/doomed.png');
    }

    public function test_gallery_audio_failure_keeps_previous_track(): void
    {
        Storage::fake('public');
        $owner = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'audio_path' => 'audio/current.mp3']);
        Storage::disk('public')->put('audio/current.mp3', 'current-track');

        Gallery::updating(function () {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $response = $this->actingAs($owner)
                ->postJson(route('admin.galleries.upload-audio', $gallery), [
                    'audio' => UploadedFile::fake()->createWithContent('new.mp3', str_repeat("\xFF\xFB\x90\x00", 2048)),
                ]);
        } finally {
            Gallery::flushEventListeners();
            Gallery::observe(SitemapCacheObserver::class);
        }

        $response->assertStatus(500);
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'audio_path' => 'audio/current.mp3']);
        Storage::disk('public')->assertExists('audio/current.mp3');

        $leftovers = array_values(array_filter(
            Storage::disk('public')->allFiles('audio'),
            fn ($p) => $p !== 'audio/current.mp3',
        ));
        $this->assertSame([], $leftovers, 'The uncommitted audio file must be cleaned up.');
    }

    public function test_venue_asset_replacement_keeps_old_file_until_save_commits(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();
        $venue = VenueTemplate::factory()->create(['thumbnail_path' => 'venue-thumbnails/current.png']);
        Storage::disk('public')->put('venue-thumbnails/current.png', 'current');

        VenueTemplate::updating(function () {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->actingAs($admin)
                ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
                ->put(route('super.venues.update', $venue), $this->venuePayload([
                    'thumbnail_image' => UploadedFile::fake()->image('replacement.png'),
                    'slug'            => $venue->slug,
                    'name'            => $venue->name,
                    'description'     => $venue->description,
                ]));
        } finally {
            VenueTemplate::flushEventListeners();
            VenueTemplate::observe(SitemapCacheObserver::class);
        }

        $this->assertDatabaseHas('venue_templates', ['id' => $venue->id, 'thumbnail_path' => 'venue-thumbnails/current.png']);
        Storage::disk('public')->assertExists('venue-thumbnails/current.png');

        $leftovers = array_values(array_filter(
            Storage::disk('public')->allFiles('venue-thumbnails'),
            fn ($p) => $p !== 'venue-thumbnails/current.png',
        ));
        $this->assertSame([], $leftovers, 'The uncommitted thumbnail must be cleaned up.');
    }

    public function test_venue_asset_replacement_removes_old_file_after_successful_save(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();
        $venue = VenueTemplate::factory()->create(['thumbnail_path' => 'venue-thumbnails/current.png']);
        Storage::disk('public')->put('venue-thumbnails/current.png', 'current');

        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), $this->venuePayload([
                'thumbnail_image' => UploadedFile::fake()->image('replacement.png'),
                'slug'            => $venue->slug,
                'name'            => $venue->name,
                'description'     => $venue->description,
            ]))
            ->assertRedirect();

        Storage::disk('public')->assertMissing('venue-thumbnails/current.png');
        $this->assertNotSame('venue-thumbnails/current.png', $venue->fresh()->thumbnail_path);
        $this->assertSame(1, count(Storage::disk('public')->allFiles('venue-thumbnails')));
    }

    // ── C. Upload validation matches real file characteristics ──────────

    public function test_venue_accepts_gltf_and_hdr_uploads(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post(route('super.venues.store'), $this->venuePayload([
                'preview_model' => UploadedFile::fake()->createWithContent(
                    'scene.gltf',
                    json_encode(['asset' => ['version' => '2.0']]),
                ),
                'hdri_file' => UploadedFile::fake()->createWithContent(
                    'environment.hdr',
                    "#?RADIANCE\nFORMAT=32-bit_rle_rgbe\n\n-Y 2 +X 2\n",
                ),
            ]))
            ->assertRedirect(route('super.venues.index'));

        $venue = VenueTemplate::where('slug', 'storage-probe-venue')->first();
        $this->assertNotNull($venue);
        $this->assertNotNull($venue->preview_model_path);
        $this->assertNotNull($venue->hdri_path);
        $this->assertStringStartsWith('venue-models/', $venue->preview_model_path);
        $this->assertStringStartsWith('venue-hdri/', $venue->hdri_path);
        Storage::disk('public')->assertExists($venue->preview_model_path);
        Storage::disk('public')->assertExists($venue->hdri_path);
    }

    public function test_venue_still_rejects_non_allowlisted_model_files(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post(route('super.venues.store'), $this->venuePayload([
                'preview_model' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'),
            ]));

        $response->assertSessionHasErrors('preview_model');
        $this->assertSame(0, VenueTemplate::where('name', 'Storage Probe Venue')->count());
    }

    // ── D. Orphaned media reconciliation ────────────────────────────────

    public function test_reconcile_command_reports_images_without_media_in_dry_run(): void
    {
        Storage::fake('public');
        Queue::fake();

        $gallery = Gallery::factory()->create();
        $image = GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'filename'   => 'lost.jpg',
            'path'       => 'storage/galleries/' . $gallery->id . '/lost.jpg',
        ]);
        Storage::disk('public')->put('galleries/' . $gallery->id . '/lost.jpg', 'legacy-bytes');

        $this->artisan('exospace:reconcile-artwork-media')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNotNull(GalleryImage::find($image->id));
    }

    public function test_reconcile_command_with_fix_dispatches_regeneration_jobs(): void
    {
        Storage::fake('public');
        Queue::fake();

        $gallery = Gallery::factory()->create();
        $image = GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'filename'   => 'recoverable.jpg',
            'path'       => 'storage/galleries/' . $gallery->id . '/recoverable.jpg',
        ]);
        Storage::disk('public')->put('galleries/' . $gallery->id . '/recoverable.jpg', 'legacy-bytes');

        $this->artisan('exospace:reconcile-artwork-media', ['--fix' => true])->assertSuccessful();

        Queue::assertPushed(RegenerateImageMedia::class, fn (RegenerateImageMedia $job) => $job->imageId === $image->id);
    }

    public function test_reconcile_command_reports_missing_legacy_files(): void
    {
        Storage::fake('public');
        Queue::fake();

        Gallery::factory()->create();
        GalleryImage::factory()->create([
            'filename' => 'ghost.jpg',
            'path'     => 'storage/galleries/1/ghost.jpg',
        ]);

        $this->artisan('exospace:reconcile-artwork-media', ['--fix' => true])->assertSuccessful();

        Queue::assertNotPushed(RegenerateImageMedia::class);
    }

    public function test_media_cleanup_and_reconciliation_are_scheduled(): void
    {
        $this->assertCommandScheduled('media-library:clean');
        $this->assertCommandScheduled('exospace:reconcile-artwork-media');
    }
}

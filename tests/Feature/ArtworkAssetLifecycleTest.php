<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use App\Observers\SitemapCacheObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ArtworkAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        GalleryImage::flushEventListeners();
        GalleryImage::observe(SitemapCacheObserver::class);

        parent::tearDown();
    }

    private function makeGallery(User $owner, array $overrides = []): Gallery
    {
        return Gallery::factory()->create(array_merge(['user_id' => $owner->id], $overrides));
    }

    private function makeImage(Gallery $gallery, array $overrides = []): GalleryImage
    {
        return GalleryImage::factory()->create(array_merge([
            'gallery_id' => $gallery->id,
            'title' => 'Artwork '.uniqid(),
        ], $overrides));
    }

    private function upload(Gallery $gallery, User $user, UploadedFile $file)
    {
        // Dropzone posts XHR (expectsJson) — assert against the same contract.
        return $this->actingAs($user)
            ->postJson(route('admin.images.store', $gallery), ['file' => $file]);
    }

    // ── A. Successful upload: rows, files and media records ─────────────

    public function test_upload_persists_artwork_with_processed_files_and_media(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $response = $this->upload($gallery, $owner, UploadedFile::fake()->image('my-artwork.jpg', 1200, 900));

        $response->assertOk()->assertJson(['success' => true]);

        $image = GalleryImage::findOrFail($response->json('id'));
        $this->assertSame($gallery->id, $image->gallery_id);
        $this->assertSame('my-artwork.jpg', $image->original_name);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.jpg$/', $image->filename);
        $this->assertSame(1200, (int) $image->width);
        $this->assertSame(900, (int) $image->height);
        $this->assertGreaterThan(0, (int) $image->size);
        $this->assertSame('landscape', $image->orientation);
        $this->assertSame(1, (int) $image->position_order);

        $mainPath = str($image->path)->after('storage/')->toString();
        Storage::disk('public')->assertExists($mainPath);
        Storage::disk('public')->assertExists(dirname($mainPath).'/thumbnails/'.$image->filename);

        $media = Media::query()
            ->where('model_type', GalleryImage::class)
            ->where('model_id', $image->id)
            ->where('collection_name', 'original')
            ->first();
        $this->assertNotNull($media, 'Spatie media record must exist for uploaded artwork');
        $this->assertSame('public', $media->disk);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
    }

    public function test_upload_truncates_oversized_client_filename(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $longName = str_repeat('x', 250).'-'.str_repeat('y', 60).'.jpg';

        $response = $this->upload($gallery, $owner, UploadedFile::fake()->image($longName, 100, 100));

        $response->assertOk();
        $image = GalleryImage::findOrFail($response->json('id'));
        $this->assertLessThanOrEqual(255, mb_strlen($image->original_name));
        $this->assertTrue(str_ends_with($image->original_name, '.jpg'));
    }

    // ── B. Rejected uploads ──────────────────────────────────────────────

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $response = $this->upload($gallery, $owner, UploadedFile::fake()->create('notes.txt', 12));

        $response->assertStatus(422);
        $this->assertSame(0, $gallery->images()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles("galleries/{$gallery->id}"));
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $response = $this->upload(
            $gallery,
            $owner,
            UploadedFile::fake()->image('huge.jpg', 100, 100)->size(11000),
        );

        $response->assertStatus(422);
        $this->assertSame(0, $gallery->images()->count());
    }

    public function test_upload_rejects_corrupt_image_with_clean_client_error(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        // JPEG magic bytes so content sniffing passes, followed by garbage
        // that no image decoder can parse.
        $corrupt = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 2048);

        $response = $this->upload(
            $gallery,
            $owner,
            UploadedFile::fake()->createWithContent('broken.jpg', $corrupt),
        );

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('corrupted', (string) $response->json('error'));
        $this->assertSame(0, $gallery->images()->count());
        $this->assertCount(0, Storage::disk('public')->allFiles("galleries/{$gallery->id}"));
    }

    public function test_upload_cleans_up_files_when_database_persist_fails(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        GalleryImage::creating(function () {
            throw new \RuntimeException('Simulated database failure');
        });

        $response = $this->upload($gallery, $owner, UploadedFile::fake()->image('doomed.jpg', 100, 100));

        $response->assertStatus(500);
        $this->assertSame(0, GalleryImage::count());
        $this->assertCount(0, Storage::disk('public')->allFiles("galleries/{$gallery->id}"));
        $this->assertSame(0, Media::query()->where('model_type', GalleryImage::class)->count());
    }

    // ── C. Metadata validation boundaries ───────────────────────────────

    public function test_metadata_rejects_year_outside_mysql_year_column_range(): void
    {
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);
        $image = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['year' => 1899])
            ->assertStatus(422)
            ->assertJsonValidationErrors('year');

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['year' => 1901])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1901, (int) $image->fresh()->year);
    }

    public function test_metadata_rejects_non_http_external_url_schemes(): void
    {
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);
        $image = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'external_url' => 'javascript://example.com%0Aalert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_url');

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'external_url' => 'ftp://example.com/portfolio',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_url');

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'external_url' => 'https://example.com/portfolio',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('https://example.com/portfolio', $image->fresh()->external_url);
    }

    public function test_public_artwork_page_never_renders_unsafe_external_url(): void
    {
        $gallery = $this->makeGallery(User::factory()->create(), ['is_active' => true]);
        $image = $this->makeImage($gallery, ['external_url' => 'javascript://example.com%0Aalert(document.cookie)']);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$image->id}");

        $response->assertOk();
        $this->assertStringNotContainsString('javascript:', $response->getContent());
    }

    // ── D. Deletion and media cleanup ────────────────────────────────────

    public function test_delete_removes_legacy_files_and_media_records(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $upload = $this->upload($gallery, $owner, UploadedFile::fake()->image('remove-me.jpg', 800, 600));
        $image = GalleryImage::findOrFail($upload->json('id'));

        $media = Media::query()->where('model_id', $image->id)->first();
        $this->assertNotNull($media);
        $legacyPath = str($image->path)->after('storage/')->toString();

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted($image);
        Storage::disk('public')->assertMissing($legacyPath);
        Storage::disk('public')->assertMissing(dirname($legacyPath).'/thumbnails/'.$image->filename);
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot('thumb'));
        $this->assertSame(
            0,
            Media::query()->where('model_type', GalleryImage::class)->where('model_id', $image->id)->count(),
        );
    }

    public function test_delete_of_artwork_without_media_or_files_succeeds(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);
        $image = $this->makeImage($gallery, ['path' => 'storage/galleries/'.$gallery->id.'/ghost.jpg']);

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted($image);
    }

    public function test_repeated_delete_of_same_artwork_returns_404(): void
    {
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);
        $image = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk();

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertNotFound();
    }

    public function test_delete_of_artwork_in_soft_deleted_gallery_404s(): void
    {
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);
        $image = $this->makeImage($gallery);
        $gallery->delete();

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertNotFound();

        $this->assertFalse($image->fresh()->trashed());
    }

    public function test_bulk_delete_cleans_up_files_and_media(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $paths = [];
        $mediaModels = [];
        foreach (['one.jpg', 'two.jpg'] as $name) {
            $upload = $this->upload($gallery, $owner, UploadedFile::fake()->image($name, 300, 300));
            $image = GalleryImage::findOrFail($upload->json('id'));
            $paths[$image->id] = str($image->path)->after('storage/')->toString();
            $mediaModels[$image->id] = Media::query()->where('model_id', $image->id)->first();
        }

        $response = $this->actingAs($owner)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => array_keys($paths)])
            ->assertOk();

        $this->assertSame(2, (int) $response->json('deleted'));

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        foreach ($mediaModels as $media) {
            Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
            Storage::disk('public')->assertMissing($media->getPathRelativeToRoot('thumb'));
        }
        $this->assertSame(0, Media::query()->where('model_type', GalleryImage::class)->count());
    }

    public function test_bulk_delete_failure_rolls_back_rows_without_deleting_files(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $first = $this->makeImage($gallery, ['title' => 'keep-row', 'path' => 'storage/galleries/'.$gallery->id.'/first.jpg']);
        $second = $this->makeImage($gallery, ['title' => 'BLOCK', 'path' => 'storage/galleries/'.$gallery->id.'/second.jpg']);

        foreach (['first.jpg', 'second.jpg'] as $file) {
            Storage::disk('public')->put("galleries/{$gallery->id}/{$file}", 'bytes');
            Storage::disk('public')->put("galleries/{$gallery->id}/thumbnails/{$file}", 'thumb');
        }

        GalleryImage::deleting(function (GalleryImage $image) {
            return $image->title !== 'BLOCK';
        });

        $response = $this->actingAs($owner)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => [$first->id, $second->id]])
            ->assertOk();

        $this->assertSame(0, (int) $response->json('deleted'));
        $this->assertFalse($response->json('success'));

        $this->assertFalse($first->fresh()->trashed(), 'Row delete must roll back when any delete in the batch fails');
        $this->assertFalse($second->fresh()->trashed());
        Storage::disk('public')->assertExists("galleries/{$gallery->id}/first.jpg");
        Storage::disk('public')->assertExists("galleries/{$gallery->id}/second.jpg");
    }

    public function test_bulk_delete_with_unknown_ids_does_not_error(): void
    {
        $owner = User::factory()->create();
        $gallery = $this->makeGallery($owner);

        $response = $this->actingAs($owner)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => [99999999]])
            ->assertOk();

        $this->assertSame(0, (int) $response->json('deleted'));
    }

    // ── E. Plan-limit accounting across teams ────────────────────────────

    public function test_team_plan_image_count_includes_member_created_galleries(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['max_images' => 1]);
        $editor = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($editor->id, ['role' => 'editor']);

        $editorGallery = Gallery::factory()->forTeam($team)->create(['user_id' => $editor->id]);
        $this->makeImage($editorGallery, ['position_order' => 1]);

        $secondTeamGallery = Gallery::factory()->forTeam($team)->create(['user_id' => $editor->id]);

        $response = $this->upload($secondTeamGallery, $editor, UploadedFile::fake()->image('extra.jpg', 100, 100));

        $response->assertStatus(422);
        $this->assertStringContainsString('Plan limit reached', (string) $response->json('error'));
        $this->assertSame(0, $secondTeamGallery->images()->count());
    }

    public function test_personal_plan_image_count_ignores_teams_the_user_is_only_member_of(): void
    {
        $owner = User::factory()->create(['max_images' => 5]);
        $otherOwner = User::factory()->create(['max_images' => 5]);
        $theirTeam = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $theirGallery = Gallery::factory()->forTeam($theirTeam)->create(['user_id' => $otherOwner->id]);
        $this->makeImage($theirGallery, ['position_order' => 1]);

        $this->assertSame(0, $owner->currentImageCount());
        $this->assertSame(1, $otherOwner->currentImageCount());
    }
}

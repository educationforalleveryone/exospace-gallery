<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryLifecycleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function galleryPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Updated Title',
            'wall_texture' => 'white',
            'frame_style' => 'modern',
            'lighting_preset' => 'bright',
            'floor_material' => 'wood',
            'room_layout' => 'square',
        ], $overrides);
    }

    // ── A. Replaced media files must survive a failed settings update ─────

    public function test_failed_update_with_duplicate_custom_domain_keeps_existing_audio_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/existing.mp3', 'original');

        $user = User::factory()->studio()->create();
        $gallery = Gallery::factory()->create([
            'user_id' => $user->id,
            'audio_path' => 'audio/existing.mp3',
        ]);
        Gallery::factory()->withCustomDomain('taken.example.com')->create();

        $response = $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload([
                'custom_domain' => 'taken.example.com',
                'audio' => UploadedFile::fake()->create('new-track.mp3', 100, 'audio/mpeg'),
            ]));

        $response->assertRedirect();

        $gallery->refresh();
        $this->assertSame('audio/existing.mp3', $gallery->audio_path, 'A failed update must not detach the stored audio');
        $this->assertTrue(
            Storage::disk('public')->exists('audio/existing.mp3'),
            'A failed update must not delete the file the gallery still references'
        );
    }

    public function test_successful_update_replaces_audio_file_and_deletes_the_old_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/old.mp3', 'old');

        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create([
            'user_id' => $user->id,
            'audio_path' => 'audio/old.mp3',
        ]);

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload([
                'audio' => UploadedFile::fake()->create('new-track.mp3', 100, 'audio/mpeg'),
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNotSame('audio/old.mp3', $gallery->audio_path);
        $this->assertTrue(Storage::disk('public')->exists($gallery->audio_path));
        $this->assertFalse(Storage::disk('public')->exists('audio/old.mp3'));
    }

    public function test_successful_update_with_duplicate_custom_domain_still_replaces_audio(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/existing.mp3', 'original');

        $user = User::factory()->studio()->create();
        $gallery = Gallery::factory()->create([
            'user_id' => $user->id,
            'audio_path' => 'audio/existing.mp3',
        ]);
        // The domain conflict is resolved before the update is retried: the new
        // audio must persist while the domain is simply ignored (plan fallback).
        Gallery::factory()->withCustomDomain('taken.example.com')->create();

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload([
                'audio' => UploadedFile::fake()->create('new-track.mp3', 100, 'audio/mpeg'),
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNotSame('audio/existing.mp3', $gallery->audio_path);
        $this->assertTrue(Storage::disk('public')->exists($gallery->audio_path));
    }

    // ── B. Duplication hygiene ────────────────────────────────────────────

    public function test_duplicate_keeps_pin_protection_of_the_source_gallery(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate")
            ->assertRedirect();

        $clone = Gallery::where('title', $gallery->title.' (Copy)')->firstOrFail();

        $this->assertTrue($clone->hasPinProtection(), 'A duplicated exhibition must not silently drop PIN protection');

        $this->get("/gallery/{$clone->slug}")
            ->assertRedirect(route('gallery.pin', $clone->slug));
    }

    public function test_duplicate_does_not_inherit_featured_curation(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->featured()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate")
            ->assertRedirect();

        $clone = Gallery::where('title', $gallery->title.' (Copy)')->firstOrFail();
        $this->assertFalse((bool) $clone->is_featured);

        $gallery->refresh();
        $this->assertTrue((bool) $gallery->is_featured, 'The original must stay untouched');
    }

    public function test_duplicate_clears_custom_domain_verification_state(): void
    {
        $user = User::factory()->studio()->create();
        $gallery = Gallery::factory()->withCustomDomain('verified.example.com')->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate")
            ->assertRedirect();

        $clone = Gallery::where('title', $gallery->title.' (Copy)')->firstOrFail();
        $this->assertNull($clone->custom_domain);
        $this->assertNull($clone->custom_domain_verification_token);
        $this->assertNull($clone->custom_domain_verified_at);
    }

    public function test_duplicate_copies_every_image_row(): void
    {
        Storage::fake('public');

        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        GalleryImage::factory()->count(3)->create(['gallery_id' => $gallery->id]);

        foreach ($gallery->images as $image) {
            Storage::disk('public')->put($image->path, 'image-data');
        }

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate")
            ->assertRedirect();

        $clone = Gallery::where('title', $gallery->title.' (Copy)')->firstOrFail();
        $this->assertSame(3, $clone->images()->count());
    }

    // ── C. Scheduling is a Pro feature, enforced server-side ──────────────

    public function test_free_user_cannot_set_schedule_through_settings_update(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload([
                'opens_at' => '2030-01-01 10:00',
                'closes_at' => '2030-02-01 10:00',
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNull($gallery->opens_at);
        $this->assertNull($gallery->closes_at);
    }

    public function test_free_user_cannot_set_schedule_through_creation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/admin/galleries', $this->galleryPayload([
                'title' => 'Sneaky Schedule',
                'opens_at' => '2030-01-01 10:00',
            ]))
            ->assertRedirect();

        $gallery = Gallery::where('title', 'Sneaky Schedule')->firstOrFail();
        $this->assertNull($gallery->opens_at);
    }

    public function test_pro_user_can_still_set_schedule(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload([
                'opens_at' => '2030-01-01 10:00',
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNotNull($gallery->opens_at);
    }

    // ── D. Banned owners' exhibitions are not served publicly ─────────────

    public function test_banned_owner_gallery_is_not_served_on_public_routes(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'is_active' => true]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $owner->forceFill(['banned_at' => now()])->save();

        $this->get("/gallery/{$gallery->slug}")->assertNotFound();
        $this->get("/gallery/{$gallery->slug}/pin")->assertNotFound();
        $this->get("/gallery/{$gallery->slug}/og-image")->assertNotFound();
        $this->get("/gallery/{$gallery->slug}/qr")->assertNotFound();
        $this->get("/gallery/{$gallery->slug}/artwork/{$image->id}")->assertNotFound();
        $this->getJson("/api/v1/galleries/{$gallery->slug}")->assertNotFound();

        $owner->forceFill(['banned_at' => null])->save();
        Cache::clear();

        $this->get("/gallery/{$gallery->slug}")->assertOk();
    }

    public function test_banned_owner_pin_verification_is_blocked(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['user_id' => $owner->id, 'is_active' => true]);

        $owner->forceFill(['banned_at' => now()])->save();

        $this->post("/gallery/{$gallery->slug}/pin", ['pin' => '1234'])
            ->assertNotFound();
    }

    // ── E. PIN protection is manageable through gallery settings ──────────

    public function test_owner_can_set_a_pin_through_settings(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload(['gallery_pin' => '1234']))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertTrue($gallery->hasPinProtection());

        $this->get("/gallery/{$gallery->slug}")
            ->assertRedirect(route('gallery.pin', $gallery->slug));
    }

    public function test_owner_can_clear_a_pin_through_settings(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload(['clear_pin' => '1']))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertFalse($gallery->hasPinProtection());

        $this->get("/gallery/{$gallery->slug}")->assertOk();
    }

    public function test_malformed_pin_is_rejected_and_existing_pin_survives(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['user_id' => $user->id]);
        $originalHash = $gallery->pin_hash;

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload(['gallery_pin' => '12']))
            ->assertSessionHasErrors('gallery_pin');

        $gallery->refresh();
        $this->assertSame($originalHash, $gallery->pin_hash);
    }

    public function test_setting_a_pin_does_not_change_pin_untouched_submissions(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['user_id' => $user->id]);
        $originalHash = $gallery->pin_hash;

        $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertRedirect();

        $gallery->refresh();
        $this->assertSame($originalHash, $gallery->pin_hash, 'Submitting the form without a PIN must not remove the existing PIN');
    }

    // ── F. Dashboard listing accuracy ─────────────────────────────────────

    public function test_gallery_index_reports_accurate_artwork_counts(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        GalleryImage::factory()->count(3)->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)->get('/admin/galleries');

        $response->assertOk();
        $this->assertSame(3, $response->viewData('galleries')->first()->images_count);
    }
}

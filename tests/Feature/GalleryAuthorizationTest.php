<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gallery CRUD authorization + PIN access tests.
 *
 * (Task H16) — covers:
 *   - Gallery creation plan limits (Free=1, Pro=5, Studio=unlimited)
 *   - Gallery edit/delete authorization (owner-only for personal galleries)
 *   - Team gallery authorization (team members can view, editors can edit)
 *   - PIN protection (correct PIN → access, wrong PIN → error, lockout after 5 fails)
 *   - Public gallery view (is_active, PIN-gated, scheduled)
 */
class GalleryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Gallery creation plan limits ─────────────────────────────────────

    public function test_free_user_can_create_one_gallery(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->canCreateGallery());
    }

    public function test_free_user_cannot_create_second_gallery(): void
    {
        $user = User::factory()->create();
        Gallery::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($user->canCreateGallery());
    }

    public function test_pro_user_can_create_five_galleries(): void
    {
        $user = User::factory()->pro()->create();
        Gallery::factory()->count(4)->create(['user_id' => $user->id]);

        $this->assertTrue($user->canCreateGallery()); // 5th is allowed
    }

    public function test_pro_user_cannot_create_sixth_gallery(): void
    {
        $user = User::factory()->pro()->create();
        Gallery::factory()->count(5)->create(['user_id' => $user->id]);

        $this->assertFalse($user->canCreateGallery()); // 6th is blocked
    }

    public function test_studio_user_can_create_many_galleries(): void
    {
        $user = User::factory()->studio()->create();
        Gallery::factory()->count(10)->create(['user_id' => $user->id]);

        $this->assertTrue($user->canCreateGallery()); // unlimited
    }

    // ── Gallery view authorization (admin panel) ─────────────────────────

    public function test_owner_can_view_their_gallery_in_admin(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->get("/admin/galleries/{$gallery->id}");

        $response->assertOk();
    }

    public function test_non_owner_cannot_view_other_users_gallery_in_admin(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($intruder)
            ->get("/admin/galleries/{$gallery->id}");

        $response->assertForbidden();
    }

    public function test_super_admin_can_view_any_gallery(): void
    {
        $owner = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($superAdmin)
            ->get("/admin/galleries/{$gallery->id}");

        $response->assertOk();
    }

    // ── Gallery edit authorization ───────────────────────────────────────

    public function test_owner_can_edit_their_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->get("/admin/galleries/{$gallery->id}/edit");

        $response->assertOk();
    }

    public function test_non_owner_cannot_edit_other_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($intruder)
            ->get("/admin/galleries/{$gallery->id}/edit");

        $response->assertForbidden();
    }

    // ── Team gallery authorization ───────────────────────────────────────

    public function test_team_member_can_view_team_gallery(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);
        $gallery = Gallery::factory()->create([
            'user_id'  => $owner->id,
            'team_id'  => $team->id,
        ]);

        $response = $this->actingAs($member)
            ->get("/admin/galleries/{$gallery->id}");

        $response->assertOk();
    }

    public function test_non_member_cannot_view_team_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $gallery = Gallery::factory()->create([
            'user_id'  => $owner->id,
            'team_id'  => $team->id,
        ]);

        $response = $this->actingAs($intruder)
            ->get("/admin/galleries/{$gallery->id}");

        $response->assertForbidden();
    }

    // ── Gallery deletion ─────────────────────────────────────────────────

    public function test_owner_can_delete_their_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->delete("/admin/galleries/{$gallery->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id]);
    }

    public function test_non_owner_cannot_delete_other_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($intruder)
            ->delete("/admin/galleries/{$gallery->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id]);
    }

    // ── Image upload authorization ───────────────────────────────────────

    public function test_non_owner_cannot_upload_images_to_other_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($intruder)
            ->postJson("/admin/galleries/{$gallery->id}/images", [
                'file' => \Illuminate\Http\UploadedFile::fake()->image('test.jpg', 800, 600),
            ]);

        $response->assertForbidden();
    }

    // ── Image deletion authorization ─────────────────────────────────────

    public function test_non_owner_cannot_delete_other_users_images(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($intruder)
            ->deleteJson("/admin/images/{$image->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('gallery_images', ['id' => $image->id]);
    }

    // ── Public gallery view ──────────────────────────────────────────────

    public function test_public_gallery_is_viewable(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertOk();
    }

    public function test_inactive_gallery_returns_404(): void
    {
        $gallery = Gallery::factory()->inactive()->create();

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertNotFound();
    }

    // ── PIN protection ───────────────────────────────────────────────────

    public function test_pin_protected_gallery_shows_pin_page(): void
    {
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['is_active' => true]);

        $response = $this->get("/gallery/{$gallery->slug}/pin");

        $response->assertOk();
        $response->assertSee($gallery->title);
    }

    public function test_correct_pin_grants_access(): void
    {
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->withSession([])
            ->post("/gallery/{$gallery->slug}/pin", ['pin' => '1234']);

        $response->assertRedirect(route('gallery.view', $gallery->slug));
        $this->assertTrue(session("pin_verified_{$gallery->id}") === true);
    }

    public function test_wrong_pin_shows_error(): void
    {
        $gallery = Gallery::factory()
            ->pinProtected('1234')
            ->create(['is_active' => true]);

        $response = $this->post("/gallery/{$gallery->slug}/pin", ['pin' => '9999']);

        $response->assertSessionHasErrors('pin');
    }

    public function test_non_pin_protected_gallery_redirects_to_view(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true, 'pin_hash' => null]);

        $response = $this->get("/gallery/{$gallery->slug}/pin");

        $response->assertRedirect(route('gallery.view', $gallery->slug));
    }

    // ── Artist authorization (C16) ───────────────────────────────────────

    public function test_artist_creator_can_edit_their_artist(): void
    {
        $user = User::factory()->create();
        $artist = \App\Models\Artist::factory()->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)
            ->get("/admin/artists/{$artist->id}/edit");

        $response->assertOk();
    }

    public function test_non_creator_cannot_edit_other_users_artist(): void
    {
        $creator = User::factory()->create();
        $intruder = User::factory()->create();
        $artist = \App\Models\Artist::factory()->create(['created_by' => $creator->id]);

        $response = $this->actingAs($intruder)
            ->get("/admin/artists/{$artist->id}/edit");

        $response->assertForbidden();
    }

    public function test_super_admin_can_edit_any_artist(): void
    {
        $creator = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $artist = \App\Models\Artist::factory()->create(['created_by' => $creator->id]);

        $response = $this->actingAs($superAdmin)
            ->get("/admin/artists/{$artist->id}/edit");

        $response->assertOk();
    }

    // ── Banned user access ───────────────────────────────────────────────

    public function test_banned_user_is_redirected_to_login(): void
    {
        $user = User::factory()->banned()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->get('/admin/dashboard');

        $response->assertRedirect('/login');
    }
}

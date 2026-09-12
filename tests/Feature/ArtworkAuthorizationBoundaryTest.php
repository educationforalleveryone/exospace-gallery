<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Artwork ownership & mutation authorization boundary (Iteration 14).
 *
 * The artwork record in Exospace is App\Models\GalleryImage. It has NO
 * row-level owner: authority flows exclusively through the parent gallery —
 *
 *     authenticated actor → GalleryPolicy (personal user_id / team roles)
 *         → authorized gallery context → GalleryImage (gallery_id)
 *
 * Adversarial coverage for the exact mission: a user who is not authorized
 * to manage the relevant gallery must not be able to manage its artwork by
 * supplying a different artwork ID, gallery ID, route parameter, request
 * payload, or reorder/bulk payload — while legitimate artwork management and
 * public presentation keep working.
 *
 * Sections:
 *   A. Authorized owner  — the full personal-gallery artwork lifecycle works
 *   B. Team context      — team→gallery→artwork chain via existing roles
 *   C. Cross-gallery     — identifier/payload manipulation cannot cross the
 *                          artwork↔gallery boundary
 *   D. Creation          — target gallery is derived server-side, never from
 *                          the payload
 *   E. Public behavior   — public artwork presentation stays public
 *
 * NOTE (process isolation, see Iteration 12/13 precedent): Team::memberRole()
 * memoizes (team_id, user_id) → role in a PHP static. Correct per-request in
 * production FPM, but a PHPUnit run is ONE process and RefreshDatabase re-uses
 * synthetic ids — so every test that reaches team-role resolution runs in its
 * own process, faithfully simulating a fresh FPM request cycle.
 */
class ArtworkAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeImage(Gallery $gallery, array $overrides = []): GalleryImage
    {
        // NOTE: the path column follows the app's real convention of a
        // 'storage/' public-disk prefix (see GalleryImageFactory) —
        // ImageProcessingService::delete() strips it via Str::after.
        return GalleryImage::factory()->create(array_merge([
            'gallery_id' => $gallery->id,
            'title' => 'Artwork '.uniqid(),
            'position_order' => 1,
        ], $overrides));
    }

    private function uploadFile(string $name = 'artwork.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 100, 80);
    }

    private function teamWithMember(string $memberRole = 'editor'): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => $memberRole]);

        return [$owner, $member, $team];
    }

    // ── A. Authorized owner — personal gallery artwork lifecycle ─────────

    public function test_owner_can_upload_artwork_into_their_gallery(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $gallery->images()->count());
        $this->assertSame($gallery->id, GalleryImage::findOrFail($response->json('id'))->gallery_id);
        $this->assertSame(1, (int) GalleryImage::findOrFail($response->json('id'))->position_order);

        // Second upload appends after the first (server-side ordering).
        $second = $this->actingAs($owner)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile('second.jpg')]);
        $second->assertOk();
        $this->assertSame(2, (int) GalleryImage::findOrFail($second->json('id'))->position_order);
    }

    public function test_owner_can_update_artwork_metadata(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'title' => 'Renamed Artwork',
                'price' => 199.99,
                'for_sale' => true,
                'currency' => 'USD',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $image->refresh();
        $this->assertSame('Renamed Artwork', $image->title);
        $this->assertTrue((bool) $image->for_sale);
        $this->assertSame('199.99', (string) $image->price);
    }

    public function test_owner_can_delete_their_artwork(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = $this->makeImage($gallery, ['path' => 'storage/galleries/'.$gallery->id.'/seeded.jpg']);
        // The DB path carries a 'storage/' URL prefix; the FILE lives on the
        // public disk at the stripped path (exactly how ImageProcessingService
        // saves uploads, and how ::delete() strips it back).
        $diskPath = str($image->path)->after('storage/')->toString();
        Storage::disk('public')->put($diskPath, 'content');
        $this->assertTrue(Storage::disk('public')->exists($diskPath));

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted($image);
        $this->assertFalse(Storage::disk('public')->exists($diskPath));
    }

    public function test_owner_can_reorder_their_artwork(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $first = $this->makeImage($gallery, ['position_order' => 1]);
        $second = $this->makeImage($gallery, ['position_order' => 2]);

        $this->actingAs($owner)
            ->postJson(route('admin.galleries.reorder-images', $gallery), [
                'order' => [$second->id, $first->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, (int) $second->fresh()->position_order);
        $this->assertSame(2, (int) $first->fresh()->position_order);
    }

    public function test_owner_can_bulk_delete_their_artwork(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $one = $this->makeImage($gallery);
        $two = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => [$one->id, $two->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertSoftDeleted($one);
        $this->assertSoftDeleted($two);
    }

    // ── B. Team context — team → gallery → artwork chain ─────────────────

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_editor_can_manage_artwork_in_team_gallery(): void
    {
        Storage::fake('public');
        [, $editor, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->forTeam($team)->create();

        // Upload through the existing team authorization (editor canEdit).
        $upload = $this->actingAs($editor)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()]);
        $upload->assertOk();
        $image = GalleryImage::findOrFail($upload->json('id'));
        $this->assertSame($gallery->id, $image->gallery_id);

        // Metadata mutation through the same boundary.
        $this->actingAs($editor)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'title' => 'Editor Renamed',
            ])
            ->assertOk();
        $this->assertSame('Editor Renamed', $image->fresh()->title);

        // Deletion through the same boundary.
        $this->actingAs($editor)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk();
        $this->assertSoftDeleted($image);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_owner_can_manage_artwork_in_editor_created_gallery(): void
    {
        Storage::fake('public');
        [$owner, $editor, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $editor->id]);
        $image = $this->makeImage($gallery);

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertOk();

        $this->assertSoftDeleted($image);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_viewer_can_view_but_not_mutate_artwork(): void
    {
        Storage::fake('public');
        [$owner, $viewer, $team] = $this->teamWithMember('viewer');
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $owner->id]);
        $image = $this->makeImage($gallery, ['title' => 'Untouchable']);

        // Viewers hold the documented view right on the team gallery
        // (show() authorizes the view policy, then redirects to the edit page).
        $this->actingAs($viewer)
            ->get(route('admin.galleries.show', $gallery))
            ->assertRedirect(route('admin.galleries.edit', $gallery));

        // …and no artwork mutation right whatsoever.
        $this->actingAs($viewer)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['title' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson(route('admin.galleries.reorder-images', $gallery), ['order' => [$image->id]])
            ->assertForbidden();

        // State untouched by every rejected mutation.
        $this->assertTrue($image->fresh()->exists);
        $this->assertFalse($image->fresh()->trashed());
        $this->assertSame('Untouchable', $image->fresh()->title);
        $this->assertSame(1, $gallery->images()->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_removed_member_cannot_mutate_team_gallery_artwork(): void
    {
        Storage::fake('public');
        [$owner, $member, $team] = $this->teamWithMember('editor');
        // Gallery once created by the now-removed member (user_id is stale
        // after removal — team authority is the only anchor).
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $member->id]);
        $image = $this->makeImage($gallery);

        $team->members()->detach($member->id);

        $this->actingAs($member)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertForbidden();

        $this->actingAs($member)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['title' => 'Hacked'])
            ->assertForbidden();

        $this->assertFalse($image->fresh()->trashed());
        $this->assertNotSame('Hacked', $image->fresh()->title);

        // The team owner still retains full control.
        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['title' => 'Owner Edit'])
            ->assertOk();
        $this->assertSame('Owner Edit', $image->fresh()->title);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_demoted_member_cannot_mutate_team_gallery_artwork(): void
    {
        Storage::fake('public');
        [$owner, $member, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $member->id]);
        $image = $this->makeImage($gallery, ['title' => 'Original']);

        // Demote editor → viewer.
        $team->members()->updateExistingPivot($member->id, ['role' => 'viewer']);

        $this->actingAs($member)
            ->deleteJson(route('admin.images.destroy', $image))
            ->assertForbidden();

        $this->actingAs($member)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['title' => 'Hacked'])
            ->assertForbidden();

        $this->assertFalse($image->fresh()->trashed());
        $this->assertSame('Original', $image->fresh()->title);

        // Demotion revokes mutation but not view (documented role model).
        $this->actingAs($member)
            ->get(route('admin.galleries.show', $gallery))
            ->assertRedirect(route('admin.galleries.edit', $gallery));

        // The team owner retains control.
        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), ['title' => 'Owner Edit'])
            ->assertOk();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_member_of_team_a_cannot_mutate_team_b_artwork(): void
    {
        Storage::fake('public');
        [, $editorA, $teamA] = $this->teamWithMember('editor');

        $otherOwner = User::factory()->create();
        $teamB = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $galleryB = Gallery::factory()->forTeam($teamB)->create();
        $imageB = $this->makeImage($galleryB, ['title' => 'Team B Artwork']);

        $this->actingAs($editorA)
            ->deleteJson(route('admin.images.destroy', $imageB))
            ->assertForbidden();

        $this->actingAs($editorA)
            ->putJson(route('admin.galleries.images.metadata', [$galleryB, $imageB]), ['title' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($editorA)
            ->postJson(route('admin.galleries.reorder-images', $galleryB), ['order' => [$imageB->id]])
            ->assertForbidden();

        $this->actingAs($editorA)
            ->post(route('admin.images.store', $galleryB), ['file' => $this->uploadFile()])
            ->assertForbidden();

        $this->assertFalse($imageB->fresh()->trashed());
        $this->assertSame('Team B Artwork', $imageB->fresh()->title);
        $this->assertSame(1, $galleryB->images()->count());
    }

    // ── C. Cross-gallery isolation — identifier/payload manipulation ─────

    public function test_metadata_for_foreign_image_under_authorized_gallery_404s_and_leaves_state_untouched(): void
    {
        $owner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $owner->id]);
        $stranger = User::factory()->create();
        $galleryB = Gallery::factory()->create(['user_id' => $stranger->id]);
        $imageB = $this->makeImage($galleryB, ['title' => 'Foreign Artwork']);

        // Valid (authorized) gallery ID + artwork from ANOTHER gallery.
        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$galleryA, $imageB]), ['title' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame('Foreign Artwork', $imageB->fresh()->title);
    }

    public function test_metadata_payload_gallery_id_cannot_reassign_artwork(): void
    {
        $owner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $owner->id]);
        $galleryB = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = $this->makeImage($galleryA, ['title' => 'Pinned In Place']);

        // Both galleries belong to the actor — the ONLY thing under test is
        // that the payload cannot move the artwork across the persisted
        // gallery relationship (the actual relationship determines
        // authorization, never the independently-presented gallery_id).
        $this->actingAs($owner)
            ->putJson(route('admin.galleries.images.metadata', [$galleryA, $image]), [
                'title' => 'Renamed In Place',
                'gallery_id' => $galleryB->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $image->refresh();
        $this->assertSame($galleryA->id, $image->gallery_id);
        $this->assertSame('Renamed In Place', $image->title);
        $this->assertSame(0, $galleryB->images()->count());
    }

    public function test_reorder_payload_containing_foreign_image_ids_is_a_scoped_noop(): void
    {
        $owner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $owner->id]);
        $stranger = User::factory()->create();
        $galleryB = Gallery::factory()->create(['user_id' => $stranger->id]);
        $foreign = $this->makeImage($galleryB, ['position_order' => 7]);
        $mineOne = $this->makeImage($galleryA, ['position_order' => 1]);
        $mineTwo = $this->makeImage($galleryA, ['position_order' => 2]);

        // Authorized gallery context + a payload stuffed with a foreign
        // artwork ID: the scoped relationship update must ignore it.
        $this->actingAs($owner)
            ->postJson(route('admin.galleries.reorder-images', $galleryA), [
                'order' => [$foreign->id, $mineTwo->id, $mineOne->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(7, (int) $foreign->fresh()->position_order);
        $this->assertSame(2, (int) $mineTwo->fresh()->position_order);
        $this->assertSame(3, (int) $mineOne->fresh()->position_order);
    }

    public function test_bulk_delete_with_mixed_ids_deletes_only_authorized_galleries_artwork(): void
    {
        $owner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $owner->id]);
        $stranger = User::factory()->create();
        $galleryB = Gallery::factory()->create(['user_id' => $stranger->id]);
        $mine = $this->makeImage($galleryA);
        $foreign = $this->makeImage($galleryB, ['title' => 'Survivor']);

        $response = $this->actingAs($owner)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => [$mine->id, $foreign->id]])
            ->assertOk();

        $this->assertSame(1, (int) $response->json('deleted'));
        $this->assertSoftDeleted($mine);
        $this->assertFalse($foreign->fresh()->trashed());
        $this->assertSame('Survivor', $foreign->fresh()->title);
        $this->assertStringContainsString('Unauthorized', (string) $response->json('errors.0'));
    }

    public function test_delete_request_for_foreign_artwork_is_rejected_and_state_untouched(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $galleryB = Gallery::factory()->create(['user_id' => $stranger->id]);
        $foreign = $this->makeImage($galleryB, ['path' => 'storage/galleries/'.$galleryB->id.'/foreign.jpg']);

        Storage::fake('public');
        $foreignDiskPath = str($foreign->path)->after('storage/')->toString();
        Storage::disk('public')->put($foreignDiskPath, 'content');

        $this->actingAs($owner)
            ->deleteJson(route('admin.images.destroy', $foreign))
            ->assertForbidden();

        $this->assertFalse($foreign->fresh()->trashed());
        $this->assertTrue(Storage::disk('public')->exists($foreignDiskPath));
    }

    // ── D. Creation — target gallery is derived server-side ──────────────

    public function test_upload_payload_gallery_id_cannot_assign_artwork_into_another_gallery(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $owner->id]);
        $galleryB = Gallery::factory()->create(['user_id' => $owner->id]);

        // The route gallery (A) is the trusted server-side target; a
        // payload gallery_id pointing at B must be ignored entirely.
        $response = $this->actingAs($owner)
            ->post(route('admin.images.store', $galleryA), [
                'file' => $this->uploadFile(),
                'gallery_id' => $galleryB->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $image = GalleryImage::findOrFail($response->json('id'));
        $this->assertSame($galleryA->id, $image->gallery_id);
        $this->assertSame(0, $galleryB->images()->count());
        $this->assertSame(1, $galleryA->images()->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_viewer_cannot_upload_artwork_into_team_gallery(): void
    {
        Storage::fake('public');
        [$owner, $viewer, $team] = $this->teamWithMember('viewer');
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $owner->id]);

        $this->actingAs($viewer)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()])
            ->assertForbidden();

        $this->assertSame(0, $gallery->images()->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_stranger_cannot_upload_artwork_into_team_gallery(): void
    {
        Storage::fake('public');
        [$owner, , $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->forTeam($team)->create(['user_id' => $owner->id]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()])
            ->assertForbidden();

        $this->assertSame(0, $gallery->images()->count());
    }

    public function test_stranger_cannot_upload_artwork_into_personal_gallery(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('admin.images.store', $gallery), ['file' => $this->uploadFile()])
            ->assertForbidden();

        $this->assertSame(0, $gallery->images()->count());
    }

    // ── E. Public behavior — presentation stays public, gates stay shut ──

    public function test_public_gallery_artwork_page_renders_for_guests(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $image = $this->makeImage($gallery, ['title' => 'Public Masterpiece']);

        $this->get("/gallery/{$gallery->slug}/artwork/{$image->id}")
            ->assertOk()
            ->assertSee('Public Masterpiece');
    }

    public function test_draft_gallery_artwork_page_404s_for_guests(): void
    {
        $gallery = Gallery::factory()->inactive()->create();
        $image = $this->makeImage($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$image->id}")
            ->assertNotFound();
    }

    public function test_pin_protected_gallery_artwork_page_redirects_to_pin_gate(): void
    {
        $gallery = Gallery::factory()->pinProtected('1234')->create(['is_active' => true]);
        $image = $this->makeImage($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$image->id}")
            ->assertRedirect(route('gallery.pin', $gallery->slug));
    }

    public function test_artwork_url_under_wrong_gallery_slug_404s(): void
    {
        $galleryA = Gallery::factory()->create(['is_active' => true]);
        $galleryB = Gallery::factory()->create(['is_active' => true]);
        $imageB = $this->makeImage($galleryB);

        // Artwork B addressed through gallery A's scoped URL.
        $this->get("/gallery/{$galleryA->slug}/artwork/{$imageB->id}")
            ->assertNotFound();
    }

    public function test_public_api_images_endpoint_still_serves_public_gallery(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $image = $this->makeImage($gallery, ['title' => 'Api Visible']);

        $this->getJson("/api/v1/galleries/{$gallery->slug}/images")
            ->assertOk()
            ->assertJsonFragment(['title' => 'Api Visible']);
    }

    public function test_draft_gallery_images_not_exposed_via_public_api(): void
    {
        $gallery = Gallery::factory()->inactive()->create();
        $image = $this->makeImage($gallery, ['title' => 'Hidden Artwork']);

        $this->getJson("/api/v1/galleries/{$gallery->slug}/images")
            ->assertNotFound();
    }
}

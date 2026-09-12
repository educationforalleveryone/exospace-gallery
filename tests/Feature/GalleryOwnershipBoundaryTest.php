<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gallery ownership & CRUD authorization boundary (Iteration 12).
 *
 * Adversarial coverage for the exact mission: a user must never be able to
 * access or mutate another user's gallery by manipulating identifiers, route
 * parameters, or request payloads — while legitimate management and public
 * presentation keep working.
 *
 * Sections:
 *   A. Authorized owner — personal gallery CRUD works server-side
 *   B. Team galleries  — current members keep the documented role model
 *   C. Cross-user      — every mutation path rejects a foreign actor
 *   D. Team boundary   — removed/demoted members lose team-gallery access
 *   E. Creation        — ownership comes from the session, not the payload
 *   F. Public behavior — public presentation + API scoping intact
 *
 * NOTE (Iteration 12 fix): GalleryPolicy previously honored the row-level
 * user_id match for TEAM galleries too, so a member who had once created a
 * team gallery kept view/update/delete rights after being removed from the
 * team (removeMember/leave only detach the pivot) or demoted to viewer.
 * The policy now resolves team galleries through current team roles only.
 */
class GalleryOwnershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /*
     * NOTE ON PROCESS ISOLATION (team tests): Team::memberRole() memoizes
     * (team_id, user_id) → role in a PHP static — per-request in production
     * FPM, but a PHPUnit run is ONE process, and RefreshDatabase re-uses the
     * same synthetic ids (team 1, user 2) in every test. Without isolation,
     * an earlier test's memoized 'editor' poisons later boundary tests that
     * reuse the id pair. Each team test below therefore runs in its own
     * process — a faithful simulation of a fresh FPM request cycle, which
     * is exactly the lifecycle the production memo assumes.
     */

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Minimal valid payload for GalleryController::update() — every
     * exhibition column is validated with required|in: whitelists.
     */
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

    private function teamWithMember(string $memberRole = 'editor'): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => $memberRole]);

        return [$owner, $member, $team];
    }

    // ── A. Authorized owner — personal gallery CRUD ──────────────────────

    public function test_owner_can_update_their_own_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'title' => 'Old Title']);

        $response = $this->actingAs($user)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'title' => 'Updated Title']);
    }

    public function test_owner_can_publish_and_unpublish_their_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->inactive()->create(['user_id' => $user->id]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)->post("/admin/galleries/{$gallery->id}/publish");
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'is_active' => true]);

        $this->actingAs($user)->post("/admin/galleries/{$gallery->id}/unpublish");
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'is_active' => false]);
    }

    public function test_owner_can_duplicate_their_gallery(): void
    {
        $user = User::factory()->pro()->create(); // duplicate consumes a gallery slot
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate");

        $response->assertRedirect();
        $this->assertDatabaseHas('galleries', [
            'user_id' => $user->id,
            'title' => $gallery->title.' (Copy)',
        ]);
    }

    public function test_owner_can_reorder_images_of_their_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $first = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 1]);
        $second = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 2]);

        $response = $this->actingAs($user)
            ->postJson("/admin/galleries/{$gallery->id}/reorder-images", [
                'order' => [$second->id, $first->id],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('gallery_images', ['id' => $second->id, 'position_order' => 1]);
        $this->assertDatabaseHas('gallery_images', ['id' => $first->id, 'position_order' => 2]);
    }

    public function test_owner_can_update_artwork_metadata_on_their_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Old']);

        $response = $this->actingAs($user)
            ->putJson("/admin/galleries/{$gallery->id}/images/{$image->id}/metadata", [
                'title' => 'New artwork title',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('gallery_images', ['id' => $image->id, 'title' => 'New artwork title']);
    }

    // ── B. Team galleries — documented role model for CURRENT members ────

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_team_editor_can_update_team_gallery(): void
    {
        [$owner, $editor, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);

        $response = $this->actingAs($editor)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'title' => 'Updated Title']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_team_owner_can_update_gallery_created_by_editor(): void
    {
        [$owner, $editor, $team] = $this->teamWithMember('editor');
        // Editor-created team gallery: row user_id = editor (store() sets
        // user_id to the acting member), team_id = team.
        $gallery = Gallery::factory()->create(['user_id' => $editor->id, 'team_id' => $team->id]);

        $response = $this->actingAs($owner)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'title' => 'Updated Title']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_team_viewer_can_view_but_not_update_team_gallery(): void
    {
        [$owner, $viewer, $team] = $this->teamWithMember('viewer');
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);

        // View path stays open for any current team member.
        $this->actingAs($viewer)
            ->get("/admin/galleries/{$gallery->id}/edit")
            ->assertOk();

        // Mutation path is editor/owner only.
        $this->actingAs($viewer)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id, 'title' => 'Updated Title']);
    }

    // ── C. Cross-user — every mutation path rejects a foreign actor ──────

    public function test_user_cannot_update_another_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'title' => 'Owner Title']);

        $this->actingAs($intruder)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertForbidden();

        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'title' => 'Owner Title']);
    }

    public function test_user_cannot_publish_or_unpublish_another_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->inactive()->create(['user_id' => $owner->id]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($intruder)
            ->post("/admin/galleries/{$gallery->id}/publish")
            ->assertForbidden();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'is_active' => false]);

        $gallery->update(['is_active' => true]);
        $this->actingAs($intruder)
            ->post("/admin/galleries/{$gallery->id}/unpublish")
            ->assertForbidden();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'is_active' => true]);
    }

    public function test_user_cannot_duplicate_another_users_gallery(): void
    {
        $owner = User::factory()->studio()->create();
        $intruder = User::factory()->studio()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->post("/admin/galleries/{$gallery->id}/duplicate")
            ->assertForbidden();

        // Only the original exists — no clone was created.
        $this->assertSame(1, Gallery::where('title', 'like', $gallery->title.'%')->count());
    }

    public function test_user_cannot_reorder_images_of_another_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 7]);

        $this->actingAs($intruder)
            ->postJson("/admin/galleries/{$gallery->id}/reorder-images", ['order' => [$image->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('gallery_images', ['id' => $image->id, 'position_order' => 7]);
    }

    public function test_user_cannot_update_metadata_on_another_users_gallery_image(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Original']);

        $this->actingAs($intruder)
            ->putJson("/admin/galleries/{$gallery->id}/images/{$image->id}/metadata", ['title' => 'Hacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('gallery_images', ['id' => $image->id, 'title' => 'Original']);
    }

    public function test_metadata_update_for_foreign_image_under_authorized_gallery_returns_404(): void
    {
        // Actor owns gallery A but targets an image belonging to gallery B
        // through gallery A's route — the scoped-URL check must 404.
        $actor = User::factory()->create();
        $foreigner = User::factory()->create();
        $galleryA = Gallery::factory()->create(['user_id' => $actor->id]);
        $galleryB = Gallery::factory()->create(['user_id' => $foreigner->id]);
        $imageB = GalleryImage::factory()->create(['gallery_id' => $galleryB->id, 'title' => 'Untouched']);

        $this->actingAs($actor)
            ->putJson("/admin/galleries/{$galleryA->id}/images/{$imageB->id}/metadata", ['title' => 'Hacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('gallery_images', ['id' => $imageB->id, 'title' => 'Untouched']);
    }

    public function test_user_cannot_view_another_users_gallery_analytics(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->get("/admin/galleries/{$gallery->id}/analytics")
            ->assertForbidden();
    }

    public function test_bulk_delete_skips_images_of_unauthorized_galleries(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($intruder)
            ->postJson('/admin/images/bulk-delete', ['ids' => [$image->id]]);

        $response->assertOk();
        $this->assertDatabaseHas('gallery_images', ['id' => $image->id]);
    }

    public function test_user_cannot_delete_another_users_gallery(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->delete("/admin/galleries/{$gallery->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'deleted_at' => null]);
    }

    // ── D. Team boundary — removed/demoted members (Iteration 12 fix) ────
    //
    // store() sets user_id to the acting member, so a team gallery row
    // carries the creator's id. removeMember/leave only detach the pivot —
    // nothing else revokes gallery access — so the policy itself must not
    // let the stale user_id match override current team roles.

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_removed_member_loses_access_to_team_gallery_they_created(): void
    {
        [$owner, $member, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

        // Owner removes the member (mirrors TeamController::removeMember —
        // pivot detach only; galleries are deliberately untouched there).
        $team->members()->detach($member->id);

        $this->actingAs($member)
            ->get("/admin/galleries/{$gallery->id}/edit")
            ->assertForbidden();

        $this->actingAs($member)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertForbidden();

        $this->actingAs($member)
            ->delete("/admin/galleries/{$gallery->id}")
            ->assertForbidden();

        // Nothing was mutated or destroyed.
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'deleted_at' => null]);

        // The team owner retains full control of the same gallery.
        $this->actingAs($owner)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertRedirect();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'title' => 'Updated Title']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_demoted_member_cannot_update_team_gallery_they_created(): void
    {
        [$owner, $member, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

        // Owner demotes the member editor → viewer.
        $team->members()->updateExistingPivot($member->id, ['role' => 'viewer']);

        $this->actingAs($member)
            ->put("/admin/galleries/{$gallery->id}", $this->galleryPayload())
            ->assertForbidden();

        $this->actingAs($member)
            ->delete("/admin/galleries/{$gallery->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'deleted_at' => null]);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_demoted_member_can_still_view_team_gallery_they_created(): void
    {
        [$owner, $member, $team] = $this->teamWithMember('editor');
        $gallery = Gallery::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

        $team->members()->updateExistingPivot($member->id, ['role' => 'viewer']);

        // View stays open for any CURRENT team member — only mutation rights
        // track the editor/owner role.
        $this->actingAs($member)
            ->get("/admin/galleries/{$gallery->id}/edit")
            ->assertOk();
    }

    // ── E. Creation — ownership from the session, not the payload ────────

    public function test_created_gallery_belongs_to_authenticated_actor_not_client_supplied_user_id(): void
    {
        $actor = User::factory()->create();
        $victim = User::factory()->create();

        $response = $this->actingAs($actor)
            ->post('/admin/galleries', $this->galleryPayload([
                'title' => 'Injection Attempt',
                'user_id' => $victim->id, // attacker-controlled ownership claim
            ]));

        $response->assertRedirect();

        $gallery = Gallery::where('title', 'Injection Attempt')->firstOrFail();
        $this->assertSame($actor->id, $gallery->user_id, 'Client-supplied user_id must never set ownership');
        $this->assertNull($gallery->team_id);
    }

    public function test_client_cannot_create_gallery_into_team_they_cannot_edit(): void
    {
        $actor = User::factory()->create();
        $foreignTeam = Team::factory()->create(); // team owned by someone else, actor not a member

        $response = $this->actingAs($actor)
            ->post('/admin/galleries', $this->galleryPayload([
                'title' => 'Team Injection',
                'team_id' => $foreignTeam->id,
            ]));

        $response->assertRedirect();

        $gallery = Gallery::where('title', 'Team Injection')->firstOrFail();
        $this->assertSame($actor->id, $gallery->user_id);
        $this->assertNull($gallery->team_id, 'A foreign team_id from the payload must be ignored');

        // And the foreign team has no new galleries.
        $this->assertSame(0, Gallery::where('team_id', $foreignTeam->id)->count());
    }

    // ── F. Public behavior — presentation and API scoping intact ─────────

    public function test_public_gallery_presentation_remains_functional(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        // Guests — public presentation needs no authentication.
        $this->get("/gallery/{$gallery->slug}")->assertOk();

        // A random authenticated user also still sees public presentation.
        $this->actingAs(User::factory()->create())
            ->get("/gallery/{$gallery->slug}")
            ->assertOk();
    }

    public function test_draft_gallery_not_exposed_via_public_api(): void
    {
        $gallery = Gallery::factory()->inactive()->create(['title' => 'Secret Draft']);

        $this->getJson("/api/v1/galleries/{$gallery->slug}")
            ->assertNotFound();

        $this->getJson('/api/v1/galleries')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Secret Draft']);
    }

    public function test_api_me_galleries_returns_only_own_galleries(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $myGallery = Gallery::factory()->create(['user_id' => $mine->id, 'title' => 'Mine']);
        $foreignGallery = Gallery::factory()->create(['user_id' => $theirs->id, 'title' => 'Theirs']);

        $token = $mine->createToken('test-read', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/me/galleries');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Mine'])
            ->assertJsonMissing(['title' => 'Theirs']);

        $this->assertSame(1, $response->json('meta.pagination.total'));
        $this->assertSame($myGallery->id, $response->json('data.0.id'));
    }
}

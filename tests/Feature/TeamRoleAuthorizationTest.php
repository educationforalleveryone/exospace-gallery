<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\AdminAuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * ITERATION 13 — Team membership & role authorization boundary tests.
 *
 * Server-side enforcement matrix for the team-management actions that
 * exist in Exospace (TeamController + TeamPolicy):
 *
 *   view               = owner OR member (any role)
 *   update (settings)  = owner OR editor (canEdit)
 *   delete             = owner only
 *   invite             = owner OR editor (canEdit)
 *   revoke invitation  = owner OR editor (canEdit) + invitation belongs to that team
 *   manageMembers      = owner only (remove member, change member role)
 *   switch / leave     = owner / members only
 *
 * Sections:
 *   A — authorized owner / editor / member behavior
 *   B — insufficient-role behavior (viewer and editor on owner-only ops)
 *   C — non-member behavior (authenticated outsider)
 *   D — cross-team boundary (Team A actor vs Team B resources)
 *   E — role-escalation attempts (client-controlled role/member values)
 *   F — member-target validation (T-1/T-2: no fabricated audit events)
 *
 * NOTE ON RunInSeparateProcess: Team::memberRole() memoizes
 * (team_id, user_id) → role in a PHP static (PERF-19). Correct under
 * production FPM (statics reset per request), but inside a single
 * PHPUnit process RefreshDatabase reuses synthetic IDs (team 1, user 2),
 * so a stale memo from an earlier test can poison a later one. Tests
 * that reach memberRole() (show / update / invite / revoke via canEdit)
 * therefore run in their own process — faithfully simulating a fresh
 * FPM request cycle. (Same pattern as GalleryOwnershipBoundaryTest,
 * Iteration 12. The remaining tests only hit isOwner()/hasMember(),
 * which are never memoized, so they are safe in-process.)
 *
 * NOTE ON LIVEWIRE (brief Section 8): verified during the Iteration-13
 * trace that NO Livewire components exist in this codebase (no
 * app/Livewire directory, no component classes); all team-management
 * interactions are classic Blade form POST/PATCH/DELETE requests to the
 * routes under test here. The server-side boundaries exercised by this
 * file are therefore exactly the interactive paths a browser can reach.
 */
class TeamRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture helpers ──────────────────────────────────────────────────

    private function memberOf(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function ownedTeam(User $owner): Team
    {
        return Team::factory()->create(['owner_id' => $owner->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // A. AUTHORIZED BEHAVIOR — each existing role can do what it is meant to
    // ═════════════════════════════════════════════════════════════════════

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_owner_can_view_team_management_page(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($owner)->get("/admin/teams/{$team->id}");

        $response->assertOk();
        $response->assertSee($member->name);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_editor_can_view_team_management_page(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');

        $this->actingAs($editor)->get("/admin/teams/{$team->id}")->assertOk();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_viewer_can_view_team_management_page(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $this->actingAs($viewer)->get("/admin/teams/{$team->id}")->assertOk();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_owner_can_update_team_settings(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $response = $this->actingAs($owner)->patch("/admin/teams/{$team->id}", [
            'name' => 'Renamed Team',
            'description' => 'Updated by owner',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Renamed Team',
            'description' => 'Updated by owner',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_editor_can_update_team_settings(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');

        $response = $this->actingAs($editor)->patch("/admin/teams/{$team->id}", [
            'name' => 'Editor Renamed',
            'description' => null,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Editor Renamed']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_owner_can_invite_a_new_member(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $response = $this->actingAs($owner)->post("/admin/teams/{$team->id}/invite", [
            'email' => 'newcollab@example.com',
            'role' => 'editor',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => 'newcollab@example.com',
            'role' => 'editor',
        ]);
        Mail::assertQueued(TeamInvitationMail::class, 1);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_editor_can_invite_a_new_member(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');

        $response = $this->actingAs($editor)->post("/admin/teams/{$team->id}/invite", [
            'email' => 'editorinvite@example.com',
            'role' => 'viewer',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => 'editorinvite@example.com',
            'role' => 'viewer',
        ]);
        Mail::assertQueued(TeamInvitationMail::class, 1);
    }

    public function test_owner_can_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'editor');

        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $member->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
        // Real membership mutation is audited.
        $this->assertTrue(
            AdminAuditLog::where('action', 'team.member_removed')
                ->where('target_type', Team::class)
                ->where('target_id', $team->id)
                ->exists(),
            'Real member removal must be audit-logged'
        );
    }

    public function test_owner_can_change_a_member_role(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($owner)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $member->id,
            'role' => 'editor',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'editor',
        ]);
        // Real role change is audited with old + new roles.
        $log = AdminAuditLog::where('action', 'team.member_role_changed')
            ->where('target_type', Team::class)
            ->where('target_id', $team->id)
            ->first();
        $this->assertNotNull($log, 'Real role change must be audit-logged');
        $this->assertSame('viewer', $log->payload['old_role']);
        $this->assertSame('editor', $log->payload['new_role']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'token' => 'revoke-me-plaintext',
        ]);

        $response = $this->actingAs($owner)
            ->delete("/admin/teams/{$team->id}/invitations/{$invitation->id}");

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
        $this->assertTrue(
            AdminAuditLog::where('action', 'team.invitation_revoked')
                ->where('target_id', $invitation->id)
                ->exists(),
            'Invitation revocation must be audit-logged'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_editor_can_revoke_a_pending_invitation(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'token' => 'editor-revoke-plaintext',
        ]);

        $response = $this->actingAs($editor)
            ->delete("/admin/teams/{$team->id}/invitations/{$invitation->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_owner_can_delete_the_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');
        $member->switchTeam($team);

        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        // Membership context is cleaned up alongside the team.
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id]);
    }

    public function test_member_can_switch_to_the_team_context(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($member)->post("/admin/teams/{$team->id}/switch");

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'current_team_id' => $team->id,
        ]);
    }

    public function test_non_owner_member_can_leave_the_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'editor');
        $member->switchTeam($team);

        $response = $this->actingAs($member)->delete("/admin/teams/{$team->id}/leave");

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
        // Their active-team context is reset when they leave.
        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'current_team_id' => null,
        ]);
    }

    public function test_owner_cannot_leave_their_own_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}/leave");

        $response->assertRedirect();
        $response->assertSessionHasErrors('team');
        // Existing business rule: the owner stays. Ownership untouched.
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'owner_id' => $owner->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // B. INSUFFICIENT-ROLE BEHAVIOR — lower-privileged roles are rejected
    // ═════════════════════════════════════════════════════════════════════

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_viewer_cannot_update_team_settings(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($viewer)->patch("/admin/teams/{$team->id}", [
            'name' => 'Viewer Renamed',
            'description' => 'should not persist',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_viewer_cannot_invite(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($viewer)->post("/admin/teams/{$team->id}/invite", [
            'email' => 'sneaky@example.com',
            'role' => 'editor',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_invitations', [
            'team_id' => $team->id,
            'email' => 'sneaky@example.com',
        ]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_viewer_cannot_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');
        $otherMember = $this->memberOf($team, 'editor');

        $response = $this->actingAs($viewer)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $otherMember->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $otherMember->id,
            'role' => 'editor',
        ]);
    }

    public function test_viewer_cannot_change_a_member_role(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');
        $otherMember = $this->memberOf($team, 'editor');

        $response = $this->actingAs($viewer)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $otherMember->id,
            'role' => 'viewer',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $otherMember->id,
            'role' => 'editor',
        ]);
    }

    public function test_viewer_cannot_delete_the_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($viewer)->delete("/admin/teams/{$team->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_editor_cannot_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($editor)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $viewer->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
    }

    public function test_editor_cannot_change_a_member_role(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($editor)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $viewer->id,
            'role' => 'editor',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
    }

    public function test_editor_cannot_delete_the_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');

        $response = $this->actingAs($editor)->delete("/admin/teams/{$team->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // C. NON-MEMBER BEHAVIOR — authenticated outsiders are rejected
    // ═════════════════════════════════════════════════════════════════════

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_non_member_cannot_view_team_page(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get("/admin/teams/{$team->id}")->assertForbidden();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_non_member_cannot_update_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->patch("/admin/teams/{$team->id}", [
            'name' => 'Hijacked Name',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => $team->name]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_non_member_cannot_invite_into_team(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post("/admin/teams/{$team->id}/invite", [
            'email' => 'victim@example.com',
            'role' => 'viewer',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_non_member_cannot_delete_team(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->delete("/admin/teams/{$team->id}")->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_non_member_cannot_remove_members(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();
        $member = $this->memberOf($team, 'editor');

        $response = $this->actingAs($outsider)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $member->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $member->id]);
    }

    public function test_non_member_cannot_change_member_roles(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();
        $member = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($outsider)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $member->id,
            'role' => 'editor',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'viewer',
        ]);
    }

    public function test_non_member_cannot_switch_into_team_context(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post("/admin/teams/{$team->id}/switch");

        $response->assertForbidden();
        $this->assertDatabaseHas('users', [
            'id' => $outsider->id,
            'current_team_id' => null,
        ]);
    }

    public function test_non_member_cannot_leave_a_team_they_are_not_in(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->delete("/admin/teams/{$team->id}/leave");

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $outsider->id]);
    }

    public function test_guest_is_redirected_to_login_for_team_management(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $this->get("/admin/teams/{$team->id}")->assertRedirect(route('login'));
    }

    public function test_team_index_lists_only_the_users_own_teams(): void
    {
        $actor = User::factory()->create();
        $otherOwner = User::factory()->create();
        $foreignTeam = $this->ownedTeam($otherOwner);

        $ownTeam = Team::factory()->create(['owner_id' => $actor->id]);
        $ownTeam->members()->attach($actor->id, ['role' => 'owner']);

        $response = $this->actingAs($actor)->get('/admin/teams');

        $response->assertOk();
        $response->assertSee($ownTeam->name);
        $response->assertDontSee($foreignTeam->name);
    }

    // ═════════════════════════════════════════════════════════════════════
    // D. CROSS-TEAM BOUNDARY — Team A actors vs Team B resources
    // ═════════════════════════════════════════════════════════════════════

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_a_owner_cannot_update_team_b(): void
    {
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $this->memberOf($teamA, 'editor');
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);

        $response = $this->actingAs($ownerA)->patch("/admin/teams/{$teamB->id}", [
            'name' => 'Captured Team B',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $teamB->id, 'name' => $teamB->name]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_a_owner_cannot_invite_into_team_b(): void
    {
        Mail::fake();

        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);

        $response = $this->actingAs($ownerA)->post("/admin/teams/{$teamB->id}/invite", [
            'email' => 'teambinvite@example.com',
            'role' => 'viewer',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_invitations', ['team_id' => $teamB->id]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_team_a_owner_cannot_remove_team_b_members(): void
    {
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);
        $memberB = $this->memberOf($teamB, 'editor');

        $response = $this->actingAs($ownerA)->delete("/admin/teams/{$teamB->id}/members", [
            'user_id' => $memberB->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $teamB->id,
            'user_id' => $memberB->id,
            'role' => 'editor',
        ]);
    }

    public function test_team_a_owner_cannot_change_team_b_member_roles(): void
    {
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);
        $memberB = $this->memberOf($teamB, 'viewer');

        $response = $this->actingAs($ownerA)->patch("/admin/teams/{$teamB->id}/members/role", [
            'user_id' => $memberB->id,
            'role' => 'editor',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $teamB->id,
            'user_id' => $memberB->id,
            'role' => 'viewer',
        ]);
    }

    public function test_team_a_owner_cannot_delete_team_b(): void
    {
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);

        $this->actingAs($ownerA)->delete("/admin/teams/{$teamB->id}")->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $teamB->id]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_a_editor_cannot_view_team_b(): void
    {
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $editorA = $this->memberOf($teamA, 'editor');
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);

        $this->actingAs($editorA)->get("/admin/teams/{$teamB->id}")->assertForbidden();
    }

    public function test_cross_team_invitation_revoke_is_rejected_with_404(): void
    {
        // The actor IS authorized on Team A, but the invitation belongs to
        // Team B — swapping the invitation ID must not leak or delete it.
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);
        $invitationB = TeamInvitation::factory()->create([
            'team_id' => $teamB->id,
            'token' => 'cross-team-plaintext',
        ]);

        $response = $this->actingAs($ownerA)
            ->delete("/admin/teams/{$teamA->id}/invitations/{$invitationB->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('team_invitations', ['id' => $invitationB->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // E. ROLE ESCALATION — client-controlled role/member values cannot
    //    elevate privileges
    // ═════════════════════════════════════════════════════════════════════

    public function test_member_role_update_rejects_owner_role_value(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($owner)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $member->id,
            'role' => 'owner',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'viewer',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_invitation_rejects_owner_role_value(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $response = $this->actingAs($owner)->post("/admin/teams/{$team->id}/invite", [
            'email' => 'escalate@example.com',
            'role' => 'owner',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('team_invitations', [
            'team_id' => $team->id,
            'email' => 'escalate@example.com',
        ]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_viewer_cannot_promote_themselves(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($viewer)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $viewer->id,
            'role' => 'editor',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
    }

    public function test_editor_cannot_promote_themselves(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = $this->memberOf($team, 'editor');

        $response = $this->actingAs($editor)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $editor->id,
            'role' => 'owner',
        ]);

        // Rejected twice over: manageMembers is owner-only (403), and even
        // if it were not, the role whitelist would reject 'owner'.
        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $editor->id,
            'role' => 'editor',
        ]);
    }

    public function test_member_cannot_remove_themselves_via_remove_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = $this->memberOf($team, 'viewer');

        $response = $this->actingAs($viewer)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $viewer->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $viewer->id,
        ]);
    }

    public function test_owner_cannot_change_their_own_role(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        // Teams created through the UI attach the owner to the pivot.
        $team->members()->attach($owner->id, ['role' => 'owner']);

        $response = $this->actingAs($owner)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $owner->id,
            'role' => 'viewer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $team->members()->attach($owner->id, ['role' => 'owner']);

        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $owner->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('user_id');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_owner_cannot_remove_a_member_by_manipulating_team_id(): void
    {
        // Owner A submits Team B's member list URL with their own member ID:
        // even a "valid team + valid member elsewhere" combination must not
        // cross the boundary.
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $memberA = $this->memberOf($teamA, 'viewer');
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);
        $memberB = $this->memberOf($teamB, 'viewer');

        $response = $this->actingAs($ownerA)->delete("/admin/teams/{$teamB->id}/members", [
            'user_id' => $memberA->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('team_user', ['team_id' => $teamA->id, 'user_id' => $memberA->id]);
        $this->assertDatabaseHas('team_user', ['team_id' => $teamB->id, 'user_id' => $memberB->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // F. MEMBER-TARGET VALIDATION (T-1/T-2) — membership mutations must
    //    only fire for real members of that team; no fabricated audit
    //    events for no-op "removals"/"role changes"
    // ═════════════════════════════════════════════════════════════════════

    public function test_remove_member_rejects_a_user_who_is_not_a_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $nonMember = User::factory()->create();

        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $nonMember->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('user_id');
        $response->assertSessionMissing('status');
        $this->assertFalse(
            AdminAuditLog::where('action', 'team.member_removed')
                ->where('target_type', Team::class)
                ->where('target_id', $team->id)
                ->exists(),
            'A removal that never happened must NOT be audit-logged (T-1)'
        );
    }

    public function test_remove_member_on_an_already_removed_member_is_rejected(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = $this->memberOf($team, 'viewer');

        // First removal is real and audited.
        $this->actingAs($owner)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $member->id,
        ])->assertSessionHas('status');

        // Second removal is a no-op — it must not fabricate a second event.
        $response = $this->actingAs($owner)->delete("/admin/teams/{$team->id}/members", [
            'user_id' => $member->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('user_id');
        $this->assertSame(
            1,
            AdminAuditLog::where('action', 'team.member_removed')
                ->where('target_type', Team::class)
                ->where('target_id', $team->id)
                ->count(),
            'Exactly one audit entry for one real removal (T-1)'
        );
    }

    public function test_update_member_role_rejects_a_user_who_is_not_a_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $nonMember = User::factory()->create();

        $response = $this->actingAs($owner)->patch("/admin/teams/{$team->id}/members/role", [
            'user_id' => $nonMember->id,
            'role' => 'editor',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('user_id');
        $response->assertSessionMissing('status');
        $this->assertFalse(
            AdminAuditLog::where('action', 'team.member_role_changed')
                ->where('target_type', Team::class)
                ->where('target_id', $team->id)
                ->exists(),
            'A role change that never happened must NOT be audit-logged (T-2)'
        );
    }

    public function test_update_member_role_rejects_a_member_of_a_different_team(): void
    {
        // The user_id IS a member — of Team B. Team A's owner must not be
        // able to "change their role" in Team A (no-op + fake audit before).
        $ownerA = User::factory()->create();
        $teamA = $this->ownedTeam($ownerA);
        $ownerB = User::factory()->create();
        $teamB = $this->ownedTeam($ownerB);
        $memberB = $this->memberOf($teamB, 'viewer');

        $response = $this->actingAs($ownerA)->patch("/admin/teams/{$teamA->id}/members/role", [
            'user_id' => $memberB->id,
            'role' => 'editor',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('user_id');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $teamB->id,
            'user_id' => $memberB->id,
            'role' => 'viewer',
        ]);
        $this->assertFalse(
            AdminAuditLog::where('action', 'team.member_role_changed')
                ->where('target_type', Team::class)
                ->where('target_id', $teamA->id)
                ->exists(),
            'Cross-team no-op must NOT be audit-logged (T-2)'
        );
    }
}

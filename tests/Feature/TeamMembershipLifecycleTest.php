<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function ownedTeam(User $owner): Team
    {
        return Team::factory()->create(['owner_id' => $owner->id]);
    }

    private function inviteTeam(Team $team, string $email, string $role = 'viewer', string $token = 'lifecycle-token'): TeamInvitation
    {
        return TeamInvitation::factory()->withToken($token)->create([
            'team_id' => $team->id,
            'email'   => $email,
            'role'    => $role,
        ]);
    }

    // ── Team creation ────────────────────────────────────────────────────

    public function test_authenticated_user_can_create_a_team_and_becomes_owner_member(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('admin.teams.store'), [
            'name' => 'Launch Crew',
        ]);

        $team = Team::where('name', 'Launch Crew')->firstOrFail();

        $response->assertRedirect(route('admin.teams.show', $team));
        $this->assertSame($user->id, $team->owner_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role'    => 'owner',
        ]);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_guest_cannot_create_a_team(): void
    {
        $response = $this->post(route('admin.teams.store'), ['name' => 'Nope']);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_invalid_team_creation_fails_without_leaving_orphans(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('admin.teams.store'), [
            'name' => str_repeat('x', 101),
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_user', 0);

        $response = $this->actingAs($user)->post(route('admin.teams.store'), [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_client_cannot_set_team_owner_through_injected_fields(): void
    {
        $creator = User::factory()->create();
        $attacker = User::factory()->create();

        $this->actingAs($creator)->post(route('admin.teams.store'), [
            'name'     => 'Hijack Attempt',
            'owner_id' => $attacker->id,
        ]);

        $team = Team::where('name', 'Hijack Attempt')->firstOrFail();

        $this->assertSame($creator->id, $team->owner_id);
        $this->assertFalse($team->hasMember($attacker));
    }

    public function test_duplicate_team_submissions_are_independent_and_safe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.teams.store'), ['name' => 'Same Name']);
        $this->actingAs($user)->post(route('admin.teams.store'), ['name' => 'Same Name']);

        $this->assertSame(2, Team::where('name', 'Same Name')->count());

        Team::where('name', 'Same Name')->get()->each(function (Team $team) use ($user) {
            $this->assertSame(1, DB::table('team_user')->where('team_id', $team->id)->count());
            $this->assertSame($user->id, $team->owner_id);
        });
    }

    // ── Membership integrity ─────────────────────────────────────────────

    public function test_duplicate_membership_is_rejected_at_the_database_level(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = User::factory()->create();

        $team->members()->attach($member->id, ['role' => 'editor']);

        $this->expectException(QueryException::class);

        DB::table('team_user')->insert([
            'team_id'    => $team->id,
            'user_id'    => $member->id,
            'role'       => 'viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Current team context ─────────────────────────────────────────────

    public function test_current_team_resolves_for_owner_and_member(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = User::factory()->create();

        $team->members()->attach($member->id, ['role' => 'viewer']);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->assertTrue($owner->currentTeam()->is($team));
        $this->assertTrue($member->currentTeam()->is($team));
    }

    public function test_current_team_returns_null_for_a_team_the_user_never_belonged_to(): void
    {
        $stranger = User::factory()->create();
        $foreignTeam = $this->ownedTeam(User::factory()->create());

        $stranger->forceFill(['current_team_id' => $foreignTeam->id])->save();

        $this->assertNull($stranger->currentTeam());
    }

    public function test_current_team_returns_null_after_membership_is_removed(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = User::factory()->create();

        $team->members()->attach($member->id, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner)->delete(route('admin.teams.remove-member', $team), [
            'user_id' => $member->id,
        ]);

        $this->assertNull($member->fresh()->current_team_id);
        $this->assertNull($member->fresh()->currentTeam());
    }

    public function test_dashboard_ignores_stale_team_context(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignTeam = $this->ownedTeam($foreignOwner);

        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => $foreignTeam->id])->save();

        $response = $this->actingAs($user)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewHas('team', null);
    }

    // ── Invitations ──────────────────────────────────────────────────────

    public function test_invitation_stores_hashed_token_and_queues_link_with_plaintext(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $this->actingAs($owner)->post(route('admin.teams.invite', $team), [
            'email' => 'newcollab@example.com',
            'role'  => 'editor',
        ]);

        Mail::assertQueued(TeamInvitationMail::class, 1);

        $invitation = TeamInvitation::where('email', 'newcollab@example.com')->firstOrFail();
        $plaintext = Mail::queued(TeamInvitationMail::class)->first()->plaintextToken;

        $this->assertNotNull($plaintext);
        $this->assertSame(hash('sha256', $plaintext), $invitation->token);
        $this->assertNotSame($plaintext, $invitation->token);
    }

    public function test_invitation_email_link_survives_queue_round_trip(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);

        $this->actingAs($owner)->post(route('admin.teams.invite', $team), [
            'email' => 'queuetrip@example.com',
            'role'  => 'viewer',
        ]);

        $mailable = Mail::queued(TeamInvitationMail::class)->first();
        $plaintext = $mailable->plaintextToken;
        $hash = TeamInvitation::hashToken($plaintext);

        $restored = unserialize(serialize($mailable));

        $this->assertSame($plaintext, $restored->plaintextToken);

        $html = $restored->render();

        $this->assertStringContainsString($plaintext, $html);
        $this->assertStringNotContainsString($hash, $html);
    }

    public function test_accept_creates_membership_from_invitation_role_ignoring_client_role(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $invitee = User::factory()->create(['email' => 'joiner@example.com']);
        $this->inviteTeam($team, 'joiner@example.com', 'viewer', 'role-injection-token');

        $response = $this->actingAs($invitee)
            ->post(route('team-invitations.accept', 'role-injection-token'), [
                'role' => 'owner',
            ]);

        $response->assertRedirect(route('admin.teams.show', $team));
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role'    => 'viewer',
        ]);
        $this->assertSame($team->id, $invitee->fresh()->current_team_id);
    }

    public function test_reused_invitation_token_cannot_be_accepted_twice(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $invitee = User::factory()->create(['email' => 'reuse@example.com']);
        $this->inviteTeam($team, 'reuse@example.com', 'editor', 'reuse-token');

        $this->actingAs($invitee)->post(route('team-invitations.accept', 'reuse-token'));

        $this->actingAs($invitee)->post(route('team-invitations.accept', 'reuse-token'))
            ->assertNotFound();

        $this->assertSame(1, DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $invitee->id)
            ->count());
    }

    public function test_second_acceptance_after_direct_add_is_cleaned_up_not_duplicated(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $invitee = User::factory()->create(['email' => 'stale@example.com']);

        $this->inviteTeam($team, 'stale@example.com', 'viewer', 'stale-invite-token');

        $team->members()->attach($invitee->id, ['role' => 'editor']);

        $response = $this->actingAs($invitee)
            ->post(route('team-invitations.accept', 'stale-invite-token'));

        $response->assertRedirect(route('admin.teams.show', $team));
        $this->assertSame(1, DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $invitee->id)
            ->count());
        $this->assertDatabaseMissing('team_invitations', ['token' => TeamInvitation::hashToken('stale-invite-token')]);
        $this->assertSame('editor', $team->fresh()->memberRole($invitee));
    }

    public function test_malformed_invitation_token_fails_safely(): void
    {
        $invitee = User::factory()->create();

        $this->actingAs($invitee)
            ->post(route('team-invitations.accept', str_repeat('z', 64)))
            ->assertNotFound();

        $this->actingAs($invitee)
            ->post(route('team-invitations.accept', '0'))
            ->assertNotFound();

        $this->assertDatabaseCount('team_user', 0);
    }

    public function test_accepting_an_invitation_only_joins_the_invited_team(): void
    {
        $owner = User::factory()->create();
        $teamA = $this->ownedTeam($owner);
        $teamB = $this->ownedTeam($owner);
        $invitee = User::factory()->create(['email' => 'single-team@example.com']);

        $this->inviteTeam($teamA, 'single-team@example.com', 'viewer', 'cross-team-token');

        $this->actingAs($invitee)->post(route('team-invitations.accept', 'cross-team-token'));

        $this->assertTrue($teamA->hasMember($invitee));
        $this->assertFalse($teamB->hasMember($invitee));
        $this->assertSame($teamA->id, $invitee->fresh()->current_team_id);
    }

    // ── Removal lifecycle / tenant isolation ─────────────────────────────

    public function test_removed_member_loses_all_team_access(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $member = User::factory()->create();

        $team->members()->attach($member->id, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner)->delete(route('admin.teams.remove-member', $team), [
            'user_id' => $member->id,
        ])->assertRedirect();

        $this->actingAs($member)->get(route('admin.teams.show', $team))->assertForbidden();
        $this->actingAs($member)->post(route('admin.teams.switch', $team))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.teams.update', $team), [
            'name' => 'Taken Over',
        ])->assertForbidden();

        $this->assertNull($member->fresh()->currentTeam());
        $this->assertSame($team->fresh()->name, $team->name);
    }

    public function test_owner_cannot_be_removed_or_demoted_via_member_endpoints(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = User::factory()->create();

        $team->members()->attach($editor->id, ['role' => 'editor']);

        $this->actingAs($editor)->delete(route('admin.teams.remove-member', $team), [
            'user_id' => $owner->id,
        ])->assertForbidden();

        $this->actingAs($editor)->patch(route('admin.teams.update-role', $team), [
            'user_id' => $owner->id,
            'role'    => 'viewer',
        ])->assertForbidden();

        $this->assertSame($owner->id, $team->fresh()->owner_id);
        $this->assertTrue($team->fresh()->isOwner($owner));
    }

    public function test_team_settings_cannot_transfer_ownership(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $attacker = User::factory()->create();

        $this->actingAs($owner)->patch(route('admin.teams.update', $team), [
            'name'     => 'Renamed Team',
            'owner_id' => $attacker->id,
        ]);

        $team->refresh();

        $this->assertSame($owner->id, $team->owner_id);
        $this->assertSame('Renamed Team', $team->name);
        $this->assertFalse($team->hasMember($attacker));
    }

    // ── UI alignment with policy ─────────────────────────────────────────

    public function test_editor_sees_the_invite_form_and_pending_invitations(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $editor = User::factory()->create();

        $team->members()->attach($editor->id, ['role' => 'editor']);
        $this->inviteTeam($team, 'pending-visible@example.com', 'viewer', 'ui-token-1');

        $response = $this->actingAs($editor)->get(route('admin.teams.show', $team));

        $response->assertOk();
        $response->assertSee('Invite a Collaborator');
        $response->assertSee('pending-visible@example.com');
    }

    public function test_viewer_does_not_see_the_invite_form(): void
    {
        $owner = User::factory()->create();
        $team = $this->ownedTeam($owner);
        $viewer = User::factory()->create();

        $team->members()->attach($viewer->id, ['role' => 'viewer']);

        $response = $this->actingAs($viewer)->get(route('admin.teams.show', $team));

        $response->assertOk();
        $response->assertDontSee('Invite a Collaborator');
    }
}

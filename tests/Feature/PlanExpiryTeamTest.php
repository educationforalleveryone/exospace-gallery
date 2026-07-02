<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Plan expiry + team invitation tests.
 *
 * (Task H17) — covers:
 *   - CheckPlanExpiry middleware: expired plan → downgrade to free
 *   - CheckPlanExpiry middleware: non-expired plan → no change
 *   - CheckBanned middleware: banned user → logged out
 *   - Team invitation: accept with matching email
 *   - Team invitation: accept with non-matching email → error
 *   - Team invitation: decline requires auth
 *   - Team invitation: decline requires email match
 *   - Team invitation: expired invitation → error
 */
class PlanExpiryTeamTest extends TestCase
{
    use RefreshDatabase;

    // ── Plan expiry ──────────────────────────────────────────────────────

    public function test_expired_plan_downgrades_to_free(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDay(), // expired yesterday
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
    }

    public function test_non_expired_plan_is_not_downgraded(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->addDays(30), // expires in 30 days
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    public function test_lifetime_plan_is_not_downgraded(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => null, // lifetime
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    public function test_free_user_is_not_affected_by_expiry_check(): void
    {
        $user = User::factory()->create([
            'plan' => 'free',
            'plan_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
    }

    // ── Banned user ──────────────────────────────────────────────────────

    public function test_banned_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->banned()->create();

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
    }

    public function test_non_banned_user_is_not_affected(): void
    {
        $user = User::factory()->create([
            'banned_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk(); // not redirected
    }

    // ── Team invitations ─────────────────────────────────────────────────

    public function test_invitation_show_page_does_not_leak_team_name_to_non_recipient(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id, 'name' => 'Secret Team']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email'   => 'invited@example.com',
            'token'   => 'test-token-123',
        ]);

        // Not logged in — should NOT see team name
        $response = $this->get('/team-invitations/test-token-123');
        $response->assertOk();
        $response->assertDontSee('Secret Team');
    }

    public function test_invitation_show_page_shows_team_name_to_matching_user(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invited@example.com']);
        $team = Team::factory()->create(['owner_id' => $owner->id, 'name' => 'My Team']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email'   => 'invited@example.com',
            'token'   => 'test-token-456',
        ]);

        $response = $this->actingAs($invitee)
            ->get('/team-invitations/test-token-456');

        $response->assertOk();
        $response->assertSee('My Team');
    }

    public function test_invitation_accept_with_matching_email_joins_team(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invited@example.com']);
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email'   => 'invited@example.com',
            'token'   => 'accept-token-123',
            'role'    => 'editor',
        ]);

        $response = $this->actingAs($invitee)
            ->post('/team-invitations/accept-token-123/accept');

        $response->assertRedirect(route('admin.teams.show', $team));
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role'    => 'editor',
        ]);
        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_invitation_accept_with_non_matching_email_fails(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create(['email' => 'intruder@example.com']);
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email'   => 'invited@example.com',
            'token'   => 'accept-token-456',
        ]);

        $response = $this->actingAs($intruder)
            ->post('/team-invitations/accept-token-456/accept');

        $response->assertSessionHasErrors('email');
    }

    public function test_invitation_decline_requires_auth(): void
    {
        TeamInvitation::factory()->create([
            'token' => 'decline-token-123',
            'email' => 'invited@example.com',
        ]);

        $response = $this->post('/team-invitations/decline-token-123/decline');

        $response->assertRedirect(); // redirect to login
    }

    public function test_invitation_decline_requires_email_match(): void
    {
        $intruder = User::factory()->create(['email' => 'intruder@example.com']);
        $invitation = TeamInvitation::factory()->create([
            'token' => 'decline-token-456',
            'email' => 'invited@example.com',
        ]);

        $response = $this->actingAs($intruder)
            ->post('/team-invitations/decline-token-456/decline');

        $response->assertSessionHasErrors('invitation');
        $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
    }

    public function test_invitation_decline_by_correct_user_deletes_invitation(): void
    {
        $invitee = User::factory()->create(['email' => 'invited@example.com']);
        $invitation = TeamInvitation::factory()->create([
            'token' => 'decline-token-789',
            'email' => 'invited@example.com',
        ]);

        $response = $this->actingAs($invitee)
            ->post('/team-invitations/decline-token-789/decline');

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $invitee = User::factory()->create(['email' => 'invited@example.com']);
        $team = Team::factory()->create();
        TeamInvitation::factory()->create([
            'team_id'   => $team->id,
            'email'     => 'invited@example.com',
            'token'     => 'expired-token-123',
            'expires_at'=> now()->subDay(), // expired
        ]);

        $response = $this->actingAs($invitee)
            ->post('/team-invitations/expired-token-123/accept');

        $response->assertSessionHasErrors('invitation');
    }
}

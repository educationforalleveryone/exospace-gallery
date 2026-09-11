<?php

namespace Tests\Feature\Auth;

use App\Mail\SuperAdminActionAlert;
use App\Models\AdminAuditLog;
use App\Models\Gallery;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ITERATION-9 — Focused coverage for the authenticated account-deletion
 * lifecycle (self-serve path: DELETE /profile → ProfileController::destroy
 * → UserDeletionService).
 *
 * Covers what the deletion flow itself owns:
 *   - authorization + the current-password sudo bar (server-side only)
 *   - deliberate confirmation semantics (wrong/missing password = no-op)
 *   - the shared-data matrix (personal galleries die, galleries in OTHER
 *     users' teams transfer to the team owner, other members' galleries in
 *     OWNED teams survive intact, owned teams cascade with the owner)
 *   - transactional safety of the DB tail
 *   - stale-credential purge (password_reset_tokens is EMAIL-keyed)
 *   - forensic parity with the admin path ('user_deleted' audit + alert)
 *   - authentication/session consequences + stale/repeat requests
 *   - the throttle bucket on the deletion endpoint
 *
 * The two pre-existing tests in ProfileTest (happy path + wrong password)
 * remain as regression anchors; this suite deep-covers the same endpoint.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Tests that simulate deletion failures register one-off User::deleting
        // closures on the static model dispatcher; flush them so they cannot
        // leak into other tests in the same process (ProfileTest precedent).
        User::flushEventListeners();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // Authorization — the server is the security boundary
    // ─────────────────────────────────────────────────────────────────

    public function test_guest_cannot_delete_any_account(): void
    {
        $victim = User::factory()->create();

        $this->delete('/profile', ['password' => 'password'])
            ->assertRedirect(route('login'));

        $this->assertNotNull($victim->fresh());
        $this->assertSame(1, User::where('email', $victim->email)->count());
    }

    public function test_user_cannot_target_another_account_via_parameters(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();

        // The route is self-scoped: no user id parameter exists. A request
        // that tries to sneak one must still resolve to the ACTING user.
        $this->actingAs($actor)
            ->delete('/profile', [
                'password' => 'password',
                'user' => $other->id,
                'user_id' => $other->id,
                'id' => $other->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($actor->fresh());
        // The "targeted" account must be untouched.
        $this->assertNotNull($other->fresh());
    }

    // ─────────────────────────────────────────────────────────────────
    // Confirmation / security — deliberate intent required
    // ─────────────────────────────────────────────────────────────────

    public function test_deletion_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertNotNull($user->fresh());
    }

    public function test_wrong_password_rejects_deletion_and_keeps_the_account_intact(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertNotNull($user->fresh());
        // The user is still authenticated — a failed attempt is not a logout.
        $this->assertAuthenticatedAs($user);
    }

    public function test_oauth_only_account_cannot_delete_without_setting_a_password(): void
    {
        // has_password=false users hold an unusable random placeholder hash;
        // the current-password gate can never pass for them (the UI now
        // shows guidance instead of the form — Iteration 8 pattern).
        $user = User::factory()->create([
            'has_password' => false,
            'password' => Hash::make(uniqid('', true)),
        ]);

        $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile', ['password' => 'any-guess'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertNotNull($user->fresh());
    }

    public function test_deletion_page_offers_guidance_instead_of_a_dead_end_for_oauth_only_accounts(): void
    {
        $user = User::factory()->create([
            'has_password' => false,
            'password' => Hash::make(uniqid('', true)),
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('Forgot password');
        // The password-confirm modal must not be offered to them.
        $this->assertStringNotContainsString('confirm-user-deletion', $response->getContent());
    }

    public function test_deletion_modal_is_offered_to_password_accounts_with_submission_guard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $this->assertStringContainsString('confirm-user-deletion', $response->getContent());
        // Iteration 9: the destructive form must carry the app-wide
        // double-submission guard and the password-manager hint.
        $this->assertStringContainsString('data-busy', $response->getContent());
        $this->assertStringContainsString('autocomplete="current-password"', $response->getContent());
    }

    // ─────────────────────────────────────────────────────────────────
    // Successful deletion — rows, files, credentials, session
    // ─────────────────────────────────────────────────────────────────

    public function test_valid_deletion_removes_the_user_their_personal_galleries_and_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $personal = Gallery::factory()->create([
            'user_id' => $user->id,
            'custom_logo_path' => 'd9-personal-logo.png',
        ]);
        Storage::disk('public')->put('d9-personal-logo.png', 'logo-bytes');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
        $this->assertNull($personal->fresh());
        Storage::disk('public')->assertMissing('d9-personal-logo.png');
    }

    public function test_deletion_purges_the_password_reset_token_for_the_freed_email(): void
    {
        $user = User::factory()->create();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'stale-token-hash',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            DB::table('password_reset_tokens')->where('email', $user->email)->count(),
            'A surviving reset token for the freed email could be replayed against whoever claims that address next.'
        );
    }

    public function test_deletion_terminates_the_session_and_blocks_reauthenticated_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertGuest();

        // Stale authenticated functionality is unreachable post-deletion.
        $this->get('/profile')->assertRedirect(route('login'));

        // A stale/repeated deletion request from the (now guest) session
        // fails safely — a login redirect, not an error.
        $this->delete('/profile', ['password' => 'password'])
            ->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Shared data — the deletion must respect team ownership
    // ─────────────────────────────────────────────────────────────────

    public function test_galleries_created_in_another_users_team_transfer_to_the_team_owner(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);

        $teamGallery = Gallery::factory()->create([
            'user_id' => $member->id,   // creator is the member…
            'team_id' => $team->id,     // …but it lives in the owner's team
            'custom_logo_path' => 'd9-team-logo.png',
        ]);
        Storage::disk('public')->put('d9-team-logo.png', 'team-logo-bytes');

        $this->actingAs($member)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        // The member is gone; the TEAM's gallery survives — re-owned by the
        // team owner, files intact.
        $this->assertNull($member->fresh());
        $fresh = $teamGallery->fresh();
        $this->assertNotNull($fresh, 'The team gallery must survive its creator.');
        $this->assertSame($owner->id, (int) $fresh->user_id, 'The gallery must be re-owned by the team owner.');
        $this->assertSame($team->id, (int) $fresh->team_id);
        Storage::disk('public')->assertExists('d9-team-logo.png');
        // The owner was untouched.
        $this->assertNotNull($owner->fresh());
    }

    public function test_other_members_galleries_in_an_owned_team_survive_the_owner_intact(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);

        $memberGallery = Gallery::factory()->create([
            'user_id' => $member->id,
            'team_id' => $team->id,
            'custom_logo_path' => 'd9-member-in-owned.png',
        ]);
        Storage::disk('public')->put('d9-member-in-owned.png', 'member-bytes');

        $this->actingAs($owner)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        // The team cascade-deletes with its owner…
        $this->assertNull($owner->fresh());
        $this->assertNull($team->fresh());
        // …but the member and their gallery survive INTACT (row + file).
        $this->assertNotNull($member->fresh());
        $fresh = $memberGallery->fresh();
        $this->assertNotNull($fresh, "The member's gallery row must survive the team's death.");
        $this->assertNull($fresh->team_id, 'The dying team only nulls galleries.team_id (FK set-null).');
        $this->assertSame($member->id, (int) $fresh->user_id);
        Storage::disk('public')->assertExists('d9-member-in-owned.png');
        $this->assertSame('member-bytes', Storage::disk('public')->get('d9-member-in-owned.png'), "The member's gallery files must NOT be stripped.");
    }

    public function test_owned_team_galleries_created_by_the_user_still_die_with_the_user(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $ownTeamGallery = Gallery::factory()->create([
            'user_id' => $owner->id,
            'team_id' => $team->id,
            'custom_logo_path' => 'd9-own-team-logo.png',
        ]);
        Storage::disk('public')->put('d9-own-team-logo.png', 'own-team-bytes');

        $this->actingAs($owner)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($owner->fresh());
        $this->assertNull($team->fresh());
        $this->assertNull($ownTeamGallery->fresh());
        Storage::disk('public')->assertMissing('d9-own-team-logo.png');
    }

    public function test_members_pointing_at_owned_teams_have_their_current_team_id_cleared(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->current_team_id, 'Members must not keep a dangling current_team_id into a cascade-deleted team.');
        $this->assertNotNull($member->fresh());
    }

    // ─────────────────────────────────────────────────────────────────
    // Transactional safety — no partial destructive state
    // ─────────────────────────────────────────────────────────────────

    public function test_a_failure_during_the_deletion_tail_leaves_no_partial_state(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'customer_name' => 'Real Name',
        ]);

        // Simulate an unexpected failure at the FINAL step (the user row
        // delete). The DB tail is transactional, so NOTHING in it may have
        // committed: no anonymization, no team-id clears, no purge.
        User::deleting(function () {
            throw new \RuntimeException('simulated deletion-tail failure');
        });

        // Surface the real exception instead of letting it become a 500
        // response, but keep AssertionFailedErrors distinct from the
        // simulated one (they share a RuntimeException ancestor).
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)
                ->delete('/profile', ['password' => 'password']);
            $this->fail('The deletion failure must surface, not be swallowed.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e, 'The simulated tail failure must be the thing that propagated.');
            $this->assertSame('simulated deletion-tail failure', $e->getMessage());
        }

        // Account + financial PII untouched — the tail rolled back as a unit.
        $this->assertNotNull($user->fresh(), 'The account must survive a failed deletion.');
        $this->assertSame($user->email, $transaction->fresh()->customer_email, 'Transactions must not be anonymized when the deletion fails.');
        $this->assertSame('Real Name', $transaction->fresh()->customer_name);
        $this->assertNotNull(User::find($user->id));
    }

    // ─────────────────────────────────────────────────────────────────
    // Forensic visibility — parity with the admin path
    // ─────────────────────────────────────────────────────────────────

    public function test_self_serve_deletion_is_audited_like_the_admin_path(): void
    {
        Mail::fake();
        $user = User::factory()->create(['plan' => 'pro']);
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $log = AdminAuditLog::where('action', 'user_deleted')->first();

        $this->assertNotNull($log, 'Self-serve deletion must be audited (the admin path always was).');
        $this->assertSame(User::class, $log->target_type);
        $this->assertSame((int) $user->id, (int) $log->target_id);
        // Payload shape matches SystemController::deleteUser, plus the
        // self-serve marker. PII hashing applies (same as the admin path):
        // the email must NOT appear in cleartext.
        $this->assertSame('pro', $log->payload['plan']);
        $this->assertTrue($log->payload['self_serve']);
        $this->assertStringStartsWith('pii:', $log->payload['email']);
        $this->assertStringNotContainsString($user->email, (string) json_encode($log->payload));

        // actor_id: nullOnDelete resolves to NULL on the production driver
        // once the user row is gone; on SQLite (tests) the FK is not
        // enforced inside RefreshDatabase, so the original id may remain.
        $this->assertTrue(
            $log->actor_id === null || (int) $log->actor_id === (int) $user->id,
            'The actor can only be the deleting user (or NULL after their own deletion).'
        );

        // The destructive-action alert reaches eligible super-admins. The
        // mailable is ShouldQueue, so it lands in the queue channel.
        Mail::assertQueued(SuperAdminActionAlert::class, function ($mail) use ($superAdmin) {
            return $mail->hasTo($superAdmin->email);
        });
    }

    public function test_audit_row_survives_the_deleted_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            AdminAuditLog::where('action', 'user_deleted')->where('target_id', $user->id)->count(),
            'The forensic row must survive the user it describes (actor_id is nullOnDelete, not cascade).'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Throttle — the sudo bar cannot be brute-forced
    // ─────────────────────────────────────────────────────────────────

    public function test_deletion_password_gate_is_throttled_in_an_isolated_bucket(): void
    {
        $user = User::factory()->create();

        // 6 wrong-password attempts fill the profile-destroy bucket…
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->from('/profile')
                ->delete('/profile', ['password' => 'wrong-'.$i])
                ->assertRedirect('/profile');
        }

        // …the 7th is rate limited, not a fifth validation round.
        $this->actingAs($user)
            ->delete('/profile', ['password' => 'wrong-6'])
            ->assertTooManyRequests();

        // The bucket is ISOLATED: the email-change endpoint on the same
        // controller keeps working (its own profile-update bucket).
        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Still Here', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        // And the account survived all of it.
        $this->assertNotNull($user->fresh());
    }
}

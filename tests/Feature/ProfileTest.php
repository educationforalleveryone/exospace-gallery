<?php

namespace Tests\Feature;

use App\Mail\EmailChangedNoticeMail;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The race-simulation test registers a one-off User::saving closure on
        // the static model dispatcher; flush it so it cannot leak into other
        // tests in the same process. (Boot-time hooks re-register on the next
        // test's fresh application instance, so this is safe.)
        User::flushEventListeners();

        parent::tearDown();
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_name_only_update_does_not_require_a_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Renamed User',
                'email' => $user->email,
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame('Renamed User', $user->refresh()->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_change_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'hijacked@example.com',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotSame('hijacked@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'hijacked@example.com',
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotSame('hijacked@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_uppercase_email_submission_is_rejected_by_the_existing_lowercase_rule(): void
    {
        // The app's existing validation convention (shared with registration)
        // REJECTS uppercase input outright instead of silently normalizing it,
        // so a case-variant submission is a clean validation error — not a
        // change, and not a verification reset.
        $user = User::factory()->create(['email' => 'same@example.com']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'SAME@EXAMPLE.COM',
            ]);

        $response->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertSame('same@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_duplicate_email_is_rejected_and_overwrites_nothing(): void
    {
        $actor = User::factory()->create(['email' => 'actor@example.com']);
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $response = $this
            ->actingAs($actor)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $actor->name,
                'email' => 'taken@example.com',
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect('/profile');

        // No account data was overwritten on either side.
        $this->assertSame('actor@example.com', $actor->refresh()->email);
        $this->assertSame('taken@example.com', $other->refresh()->email);
        $this->assertNotNull($actor->email_verified_at);
        $this->assertSame(1, DB::table('users')->where('email', 'taken@example.com')->count());
    }

    public function test_users_email_column_is_backed_by_a_database_unique_index(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        // The application-level pre-check is not the last line of defense —
        // the schema itself must arbitrate duplicates (race safety).
        $this->expectException(UniqueConstraintViolationException::class);

        User::create([
            'name' => 'Dupe',
            'email' => 'dupe@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_concurrent_email_change_race_lands_cleanly(): void
    {
        $user = User::factory()->create(['email' => 'actor@example.com']);

        // Simulate a concurrent request winning the window between the
        // validation layer's unique pre-check and this request's save: a
        // rival row with the SAME target address is inserted the moment the
        // actor's save begins. The DB unique index must reject the update and
        // the endpoint must fail cleanly instead of erroring with a 500.
        User::saving(function () {
            DB::table('users')->insert([
                'name' => 'Racer',
                'email' => 'claimed@example.com',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'claimed@example.com',
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect('/profile');

        // The actor's account is untouched and stays verified.
        $this->assertSame('actor@example.com', $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_completed_email_change_runs_the_full_lifecycle(): void
    {
        Notification::fake();
        Mail::fake();

        $user = User::factory()->create(['email' => 'before@example.com']);

        // A stale reset token for the address about to be FREED.
        DB::table('password_reset_tokens')->insert([
            'email' => 'before@example.com',
            'token' => hash_hmac('sha256', 'stale-token', config('app.key')),
            'created_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'after@example.com',
                'password' => 'password',
            ]);

        // One-hop redirect to the verification prompt with an acknowledgment
        // that actually renders (no double-hop flash loss).
        $response
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        $user->refresh();
        $this->assertSame('after@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        // Stale reset token for the freed old address is gone.
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'before@example.com')->count());

        // Verification lifecycle initiated for the NEW address. The
        // NotificationFake indexes by EXACT class, and the class this app
        // sends differs between codebases (framework default here, a branded
        // subclass on deployments that override the sender) — so read the
        // raw store and assert on the recorded INSTANCE instead.
        $store = \Closure::bind(
            fn () => $this->notifications,
            Notification::getFacadeRoot(),
            get_class(Notification::getFacadeRoot())
        )();

        // Store shape: [notifiableClass][notifiableKey][notificationClass][] = entry
        $recorded = [];
        foreach ($store as $byKey) {
            foreach ($byKey as $byClass) {
                foreach ($byClass as $entries) {
                    foreach ($entries as $entry) {
                        $recorded[] = $entry;
                    }
                }
            }
        }
        $recorded = collect($recorded);

        $this->assertSame(1, $recorded->count());
        $this->assertInstanceOf(
            FrameworkVerifyEmail::class,
            $recorded[0]['notification']
        );
        $this->assertContains('mail', $recorded[0]['channels']);

        // Security notice queued to the OLD address, never the new one.
        Mail::assertQueued(EmailChangedNoticeMail::class, function (EmailChangedNoticeMail $mail) use ($user) {
            return $mail->hasTo('before@example.com')
                && $mail->envelope()->subject === 'Your Exospace email address was changed'
                && ! $mail->hasTo($user->email);
        });

        // Identity-critical change is audited like mfa.enabled/mfa.disabled.
        $this->assertTrue(
            AdminAuditLog::query()
                ->where('action', 'email_changed')
                ->where('actor_id', $user->id)
                ->where('target_id', $user->id)
                ->exists()
        );

        // The change flow must not leak credentials or MFA material.
        Mail::assertNothingSent();
    }

    public function test_old_verification_link_cannot_verify_the_new_address(): void
    {
        $user = User::factory()->create(['email' => 'before@example.com']);

        // A signed verification link minted while the OLD address was current
        // (framework structure: id + sha1(email)).
        $oldLink = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1('before@example.com'),
        ]);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'after@example.com',
                'password' => 'password',
            ]);

        $response = $this->actingAs($user)->get($oldLink);

        // The link is bound to the address it was minted for — it must not
        // verify the account's NEW address, and the account stays unverified.
        $response->assertForbidden();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_session_remains_consistent_after_email_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'moved@example.com',
                'password' => 'password',
            ]);

        // The authenticated session survives the change and still resolves
        // to the same user account.
        $this->assertAuthenticatedAs($user);

        // The user can reach the verification prompt for the new address.
        $this->get(route('verification.notice'))->assertOk();
    }

    public function test_old_email_no_longer_authenticates_the_account(): void
    {
        $user = User::factory()->create(['email' => 'before@example.com']);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'after@example.com',
                'password' => 'password',
            ]);

        $this->post('/logout');
        auth()->logout();

        // Login uses email as the credential: the old address must no longer
        // match the account; the new address must.
        $this->post('/login', [
            'email' => 'before@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'after@example.com',
            'password' => 'password',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_change_neutralizes_mass_assignment(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'mass@example.com',
                'password' => 'password',
                // Unsafe parameters that must never reach the model through
                // the validated() payload.
                'is_super_admin' => '1',
                'plan' => 'pro',
                'max_galleries' => '9999',
                'google2fa_secret' => 'FAKESECRET',
                'mfa_backup_codes' => '["fake"]',
                'email_verified_at' => now()->toIso8601String(),
            ]);

        $user->refresh();
        $this->assertSame('mass@example.com', $user->email);
        $this->assertFalse($user->is_super_admin);
        $this->assertSame('free', $user->plan);
        $this->assertSame(1, $user->max_galleries);
        $this->assertNull($user->google2fa_secret);
        $this->assertNull($user->mfa_backup_codes);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_change_is_rate_limited(): void
    {
        $user = User::factory()->create();

        // The current-password identity check must not be brute-forceable
        // through the session: 6 attempts per minute, then 429 (the same bar
        // as the MFA disable endpoint).
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->patch('/profile', [
                    'name' => 'Name '.$i,
                    'email' => $user->email,
                ])
                ->assertRedirect('/profile');
        }

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Name 7',
                'email' => $user->email,
            ])
            ->assertTooManyRequests();
    }

    public function test_guest_cannot_change_any_email(): void
    {
        $this->patch('/profile', [
            'name' => 'Ghost',
            'email' => 'ghost@example.com',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('users')->where('email', 'ghost@example.com')->count());
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}

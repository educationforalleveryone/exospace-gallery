<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailChangedNoticeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_cannot_view_or_update_profile(): void
    {
        $this->get('/profile')->assertRedirect('/login');

        $this->patch('/profile', [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
        ])->assertRedirect('/login');
    }

    public function test_unverified_users_are_redirected_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_profile_page_renders_persisted_identity_for_its_owner(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee($user->email, false);
    }

    public function test_owner_can_update_name_without_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $originalPasswordSetAt = $user->password_set_at;

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Updated Name',
                'email' => $user->email,
                'password' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile')
            ->assertSessionHas('status', 'profile-updated');

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame($originalHash, $user->password);
        $this->assertEquals($originalPasswordSetAt, $user->password_set_at);
        $this->assertTrue($user->has_password);
    }

    public function test_typed_password_with_unchanged_email_does_not_rotate_hash(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Still Same',
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_name_is_required_and_limited_to_255_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => '', 'email' => $user->email])
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->patch('/profile', ['name' => str_repeat('a', 256), 'email' => $user->email])
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->patch('/profile', ['name' => str_repeat('a', 255), 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('a', 255), $user->refresh()->name);
    }

    public function test_email_must_be_a_valid_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => $user->name, 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame($user->refresh()->email, $user->getOriginal('email'));
    }

    public function test_email_owned_by_another_user_fails_cleanly_and_preserves_state(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'taken@example.com',
                'password' => 'password',
            ])
            ->assertSessionHasErrorsIn('default', 'email')
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotSame('taken@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $this->actingAs($user)->get('/profile')->assertSee('taken@example.com', false);
    }

    public function test_unexpected_fields_cannot_be_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'New Name',
                'email' => $user->email,
                'id' => 999,
                'plan' => 'studio',
                'is_super_admin' => 1,
                'max_galleries' => 999,
                'max_images' => 999,
                'google2fa_secret' => 'attacker-controlled',
                'email_verified_at' => '2020-01-01 00:00:00',
                'has_password' => 0,
                'marketing_consent' => 1,
                'subscription_status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertNotEquals(999, $user->id);
        $this->assertSame('free', $user->plan);
        $this->assertFalse($user->is_super_admin);
        $this->assertSame(1, $user->max_galleries);
        $this->assertSame(10, $user->max_images);
        $this->assertNull($user->google2fa_secret);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->has_password);
        $this->assertFalse($user->marketing_consent);
        $this->assertNull($user->subscription_status);
    }

    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'password' => '',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_change_with_wrong_password_fails(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_email_change_with_valid_password_unverifies_and_sends_notifications(): void
    {
        Mail::fake();
        Notification::fake();

        $user = User::factory()->create();
        $oldEmail = $user->email;

        DB::table('password_reset_tokens')->insert([
            'email' => $oldEmail,
            'token' => Str::random(32),
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'email_changed']);

        Notification::assertSentTo($user, \App\Notifications\Auth\VerifyEmail::class);
        Mail::assertQueued(EmailChangedNoticeMail::class, fn (EmailChangedNoticeMail $mail) => $mail->hasTo($oldEmail));
    }

    public function test_email_change_does_not_rotate_password_hash(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'new@example.com',
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_mixed_case_email_is_rejected_and_state_preserved(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'Mixed.Case@Exospace.Gallery',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_uppercase_email_submission_is_rejected_strictly(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        Mail::fake();
        Notification::fake();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'USER@EXAMPLE.COM',
                'password' => '',
            ])
            ->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertSame('user@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_unchanged_identity_does_not_trigger_email_lifecycle(): void
    {
        $user = User::factory()->create();
        Mail::fake();
        Notification::fake();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => '',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHas('status', 'profile-updated');

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_name_cannot_inject_markup(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_multibyte_name_initial_renders_in_navigation(): void
    {
        $user = User::factory()->create(['name' => '日暮瑠美']);

        $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/bg-brand-600 flex items-center justify-center[^>]*>\s*日\s*</u',
            $html
        );
    }

    public function test_persisted_identity_is_displayed_after_update(): void
    {
        $user = User::factory()->create(['name' => 'Before']);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'After',
                'email' => $user->email,
                'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('After')
            ->assertDontSee('>Before<', false);
    }
}

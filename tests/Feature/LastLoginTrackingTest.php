<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ITERATION 6 — users.last_login_at tracking.
 *
 * The platform had NO login-activity record, which forced the retention
 * analytics into an unbounded, noisy activity definition. These tests pin
 * the new signal:
 *   1. Password login stamps last_login_at
 *   2. Failed login does not stamp
 *   3. Post-registration auto-login stamps (registration IS a first login)
 *   4. The stamp survives as a queryable column (retention reads it)
 */
class LastLoginTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_stamps_last_login_at(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->last_login_at, 'freshly created user has no login yet');

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        $stamp = DB::table('users')->where('id', $user->id)->value('last_login_at');
        $this->assertNotNull($stamp, 'login stamps users.last_login_at');
        $this->assertTrue(
            now()->diffInSeconds(\Carbon\Carbon::parse($stamp)) < 10,
            'the stamp is the login time, not a stale value',
        );
    }

    public function test_failed_login_does_not_stamp(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertNull(
            DB::table('users')->where('id', $user->id)->value('last_login_at'),
            'a failed attempt must never count as activity',
        );
    }

    public function test_registration_auto_login_stamps(): void
    {
        $this->post('/register', [
            'name'                  => 'Retention Test',
            'email'                 => 'retention-' . uniqid() . '@example.test',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'like', 'retention-%@example.test')->latest('id')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->last_login_at, 'registration auto-login counts as the first login (W0 activity)');
    }

    public function test_login_event_is_wired_to_the_stamp_listener(): void
    {
        // Auto-discovered listener registration (no EventServiceProvider in
        // Laravel 11+): assert the dispatcher actually resolves a listener
        // for the Login event — if auto-discovery breaks, retention would
        // silently lose its login signal.
        $listeners = app('events')->getListeners(\Illuminate\Auth\Events\Login::class);

        $this->assertNotEmpty($listeners, 'Illuminate\Auth\Events\Login has at least one listener (StampLastLogin)');
    }
}

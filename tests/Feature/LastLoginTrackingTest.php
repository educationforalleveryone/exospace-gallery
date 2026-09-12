<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
        $listeners = app('events')->getListeners(\Illuminate\Auth\Events\Login::class);

        $this->assertNotEmpty($listeners, 'Illuminate\Auth\Events\Login has at least one listener (StampLastLogin)');
    }
}

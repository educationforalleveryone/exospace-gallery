<?php

namespace Tests\Feature\Auth;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ── Session creation / regeneration keeps working ────────────────────

    public function test_the_rotated_session_id_keeps_authenticating_after_login(): void
    {
        $user = User::factory()->create();

        // Pre-login session (attacker-known ID).
        $this->get('/login');
        $idBeforeLogin = session()->getId();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($idBeforeLogin, session()->getId());

        $rawSessionCookie = $login->getCookie(config('session.cookie'), decrypt: false)?->getValue();
        $this->assertNotNull($rawSessionCookie, 'A successful login must issue a fresh session cookie.');

        $this->withCookie(config('session.cookie'), $rawSessionCookie)
            ->get('/profile')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_destroys_only_the_current_session_record_leaving_sibling_sessions_intact(): void
    {
        $this->createTestSessionsTable();
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $guardKey = Auth::guard('web')->getName();

        $payload = serialize([$guardKey => $user->id]);
        $authPayload = config('session.encrypt')
            ? base64_encode(app('encrypter')->encrypt($payload))
            : base64_encode($payload);

        $sessionA = Str::random(40);
        $sessionB = Str::random(40);
        foreach ([$sessionA, $sessionB] as $id) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
                'user_agent' => 'test', 'payload' => $authPayload, 'last_activity' => now()->timestamp,
            ]);
        }

        $this->withCleanGuardState()
            ->withCookie(config('session.cookie'), $sessionA)
            ->post('/logout')
            ->assertRedirect('/');

        // A is destroyed server-side…
        $this->assertSame(0, DB::table('sessions')->where('id', $sessionA)->count(), 'Logout must destroy the current session record.');

        // …and B is untouched — the sibling session survives byte-for-byte.
        $this->assertSame(1, DB::table('sessions')->where('id', $sessionB)->count(), 'Logout must never purge sibling sessions of the same user.');
        $this->assertSame($authPayload, DB::table('sessions')->where('id', $sessionB)->value('payload'));
    }

    // ── Server-authoritative session state (§7/§10) ──────────────────────

    public function test_a_server_side_destroyed_session_cannot_be_resurrected_by_the_stale_cookie(): void
    {
        $user = User::factory()->create();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $login->assertRedirect();

        $rawSessionCookie = $login->getCookie(config('session.cookie'), decrypt: false)?->getValue();
        $this->assertNotNull($rawSessionCookie);

        $handler = $this->app['session.store']->getHandler();
        $storageProperty = new ReflectionProperty($handler, 'storage');
        $storageProperty->setValue($handler, []);
        $this->freshSessionStack();

        $this->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie)
            ->get('/profile')
            ->assertRedirect(route('login'));
    }

    public function test_the_session_cookie_carries_secure_http_only_same_site_and_lifetime(): void
    {
        $user = User::factory()->create();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $login->assertRedirect();

        $cookie = $login->getCookie(config('session.cookie'), decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure(), 'the session cookie must be Secure by default.');
        $this->assertTrue($cookie->isHttpOnly(), 'The session cookie must be HttpOnly.');
        $this->assertSame('lax', $cookie->getSameSite(), 'The session cookie must be SameSite=Lax.');

        $this->assertSame(
            config('session.lifetime') * 60,
            $cookie->getMaxAge(),
            'The session cookie expiry must match the session lifetime.'
        );
    }

    public function test_session_payload_is_encrypted_when_the_sec_11_toggle_is_enabled(): void
    {
        $user = User::factory()->create();

        config(['session.driver' => 'array', 'session.encrypt' => true]);
        $this->freshSessionStack();
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect();
        $sessionId = $login->getCookie(config('session.cookie'))?->getValue();
        $payload = $this->app['session.store']->getHandler()->read($sessionId);

        $this->assertStringStartsWith('eyJ', trim((string) $payload), 'Encrypted sessions must store ciphertext.');
        $this->assertStringNotContainsString('login_web_', (string) $payload);
    }

    public function test_session_payload_is_readable_when_encryption_is_disabled_for_local_dev(): void
    {
        $user = User::factory()->create();

        config(['session.driver' => 'array', 'session.encrypt' => false]);
        $this->freshSessionStack();
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect();
        $sessionId = $login->getCookie(config('session.cookie'))?->getValue();
        $payload = $this->app['session.store']->getHandler()->read($sessionId);

        $this->assertStringContainsString('login_web_', (string) $payload);
    }

    public function test_session_idle_lifetime_and_close_behavior_are_the_configured_values(): void
    {
        $this->assertSame(120, config('session.lifetime'));
        $this->assertFalse(config('session.expire_on_close'));
    }

    // ── Banned-user session purge: redis branch (S-1 fix) ────────────────

    public function test_purge_command_redis_branch_deletes_only_the_banned_users_encrypted_sessions(): void
    {
        $guardKey = Auth::guard('web')->getName();
        $bannedUser = User::factory()->create(['banned_at' => now()]);
        $healthyUser = User::factory()->create();

        $this->simulateRedisSessionStore();

        $prefix = config('cache.prefix');
        $bannedSessionId = Str::random(40);
        $healthySessionId = Str::random(40);
        $junkKey = $prefix.'galleries:9:conversions';
        $garbageSessionId = Str::random(40);

        $this->seedSessionPayload($bannedSessionId, $guardKey, $bannedUser->id);
        $this->seedSessionPayload($healthySessionId, $guardKey, $healthyUser->id);
        Cache::store('array')->put($garbageSessionId, 'not-a-session-payload', 120);

        Redis::shouldReceive('connection')->with('cache')->andReturnSelf();
        Redis::shouldReceive('scan')->with(null, ['match' => $prefix.'*', 'count' => 100])
            ->andReturn([0, [
                $prefix.$bannedSessionId,
                $prefix.$healthySessionId,
                $junkKey,             // must never even be read
                $prefix.$garbageSessionId, // read, must not match, must survive
            ]]);

        $this->artisan('exospace:purge-banned-sessions')
            ->expectsOutputToContain('Deleted 1 session(s).')
            ->assertSuccessful();

        $this->assertNull(Cache::store('array')->get($bannedSessionId), 'The banned user\'s encrypted session must be deleted.');
        $this->assertNotNull(Cache::store('array')->get($healthySessionId), 'Other users\' sessions must be untouched.');
        $this->assertNotNull(Cache::store('array')->get($garbageSessionId), 'Undecryptable payloads must never be treated as matches.');
    }

    public function test_purge_command_redis_branch_matches_plaintext_payloads_when_encryption_is_disabled(): void
    {
        $guardKey = Auth::guard('web')->getName();
        $bannedUser = User::factory()->create(['banned_at' => now()]);

        $this->simulateRedisSessionStore();
        config(['session.encrypt' => false]);
        $prefix = config('cache.prefix');
        $sessionId = Str::random(40);

        Cache::store('array')->put($sessionId, serialize([
            '_token' => Str::random(40),
            $guardKey => $bannedUser->id,
        ]), 120);

        Redis::shouldReceive('connection')->with('cache')->andReturnSelf();
        Redis::shouldReceive('scan')->with(null, ['match' => $prefix.'*', 'count' => 100])
            ->andReturn([0, [$prefix.$sessionId]]);

        $this->artisan('exospace:purge-banned-sessions')
            ->expectsOutputToContain('Deleted 1 session(s).')
            ->assertSuccessful();

        $this->assertNull(Cache::store('array')->get($sessionId));
    }

    // ── Banned-user session purge: database branch (S-2/S-1 parity) ──────

    public function test_purge_command_database_branch_deletes_only_banned_users_rows(): void
    {
        $this->createTestSessionsTable();
        config(['session.driver' => 'database']);

        $bannedUser = User::factory()->create(['banned_at' => now()]);
        $healthyUser = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => Str::random(40), 'user_id' => $bannedUser->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
            ['id' => Str::random(40), 'user_id' => $healthyUser->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
        ]);

        $this->artisan('exospace:purge-banned-sessions')
            ->expectsOutputToContain('Purged 1 sessions')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('sessions')->where('user_id', $healthyUser->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $bannedUser->id)->count());
    }

    public function test_purge_command_skips_gracefully_for_the_file_driver(): void
    {
        config(['session.driver' => 'file']);

        User::factory()->create(['banned_at' => now()]);

        $this->artisan('exospace:purge-banned-sessions')
            ->expectsOutputToContain('SESSION_DRIVER=file')
            ->assertSuccessful();
    }

    // ── Ban flow truthfulness (S-2) + CheckBanned (S-3) ───────────────────

    public function test_banning_a_user_reports_the_truth_about_session_purging_and_enforces_the_ban(): void
    {
        $admin = $this->enrolledSuperAdmin();
        $target = User::factory()->create();

        // Non-database driver (the production shape: no sessions table).
        config(['session.driver' => 'array']);

        Log::spy();

        $response = $this->actingAs($admin)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
            ])
            ->post(route('super.banUser', $target), ['reason' => 'probe']);

        $response->assertRedirect();

        $audit = AdminAuditLog::where('action', 'user_banned')
            ->where('target_id', $target->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertFalse(
            $audit->payload['sessions_purged'],
            'Non-database drivers cannot purge a sessions table — the audit must say so.'
        );
        $this->assertTrue($audit->payload['tokens_revoked']);

        $this->assertNull($target->fresh()->remember_token);

        // S-3: no per-request purge attempt against the nonexistent table.
        Log::shouldNotHaveReceived('warning', function (string $message) {
            return str_contains($message, 'failed to purge');
        });

        $bannedResponse = $this->actingAs($target)->get('/dashboard');
        $bannedResponse->assertRedirect(route('login'));
        $bannedResponse->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_banning_a_user_purges_sessions_rows_on_the_database_driver(): void
    {
        $this->createTestSessionsTable();
        config(['session.driver' => 'database']);

        $admin = $this->enrolledSuperAdmin();
        $target = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => Str::random(40), 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
            ])
            ->post(route('super.banUser', $target), ['reason' => 'probe'])
            ->assertRedirect();

        $audit = AdminAuditLog::where('action', 'user_banned')->where('target_id', $target->id)->latest('id')->first();
        $this->assertTrue($audit->payload['sessions_purged'], 'The database driver genuinely purges session rows.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    // ── Impersonation identity transitions (S-4) ─────────────────────────

    public function test_impersonation_start_rotates_the_session_id_and_preserves_the_admin_anchor(): void
    {
        $this->enableImpersonation();

        $admin = $this->enrolledSuperAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->withSession($this->mfaVerifiedSession($admin))
            ->get('/verify-email'); // establish the pre-impersonation session
        $idBefore = session()->getId();

        $this->actingAs($admin)
            ->withSession(array_merge($this->mfaVerifiedSession($admin), [
                'auth.password_confirmed_at' => now()->timestamp,
            ]))
            ->post(route('super.impersonate', $target))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target, 'web');
        $this->assertSame($admin->id, session('impersonating_admin_id'));
        $this->assertNotSame(
            $idBefore,
            session()->getId(),
            'An identity transition must rotate the session ID (parity with login/registration/OAuth).'
        );
    }

    public function test_impersonation_stop_rotates_again_and_restores_the_admin(): void
    {
        $this->enableImpersonation();

        $admin = $this->enrolledSuperAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(array_merge($this->mfaVerifiedSession($admin), [
                'auth.password_confirmed_at' => now()->timestamp,
            ]))
            ->post(route('super.impersonate', $target))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target, 'web');
        $idWhileImpersonating = session()->getId();

        $this->post(route('super.stop-impersonating'))->assertRedirect();

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNull(session('impersonating_admin_id'));
        $this->assertNotSame(
            $idWhileImpersonating,
            session()->getId(),
            'Stopping impersonation is also an identity transition and must rotate the session ID.'
        );
    }

    public function test_logging_out_while_impersonating_terminates_both_identities(): void
    {
        $this->enableImpersonation();

        $admin = $this->enrolledSuperAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(array_merge($this->mfaVerifiedSession($admin), [
                'auth.password_confirmed_at' => now()->timestamp,
            ]))
            ->post(route('super.impersonate', $target))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target, 'web');
        $this->assertSame($admin->id, session('impersonating_admin_id'));

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(session('impersonating_admin_id'));
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->post(route('super.stop-impersonating'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function simulateRedisSessionStore(): void
    {
        config([
            'session.driver' => 'redis',
            'session.store' => 'array',
            'cache.prefix' => 'exospace-cache-',
            'session.encrypt' => true,
        ]);
    }

    private function seedSessionPayload(string $sessionId, string $guardKey, int $userId): void
    {
        Cache::store('array')->put($sessionId, app('encrypter')->encrypt(serialize([
            '_token' => Str::random(40),
            $guardKey => $userId,
        ])), 120);
    }

    private function freshSessionStack(): void
    {
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('session');
        $this->app['auth']->forgetGuards();
    }

    private function withCleanGuardState(): self
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function rawSessionCookieFor(string $sessionId): string
    {
        $name = config('session.cookie');
        $prefix = \Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey());

        return app('encrypter')->encrypt($prefix.$sessionId, false);
    }

    private function createTestSessionsTable(): void
    {
        if (! \Schema::hasTable('sessions')) {
            \Schema::create('sessions', function ($table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    private function mfaVerifiedSession(User $user): array
    {
        // Same seeding contract MfaLifecycleTest uses (user-bound flag).
        return [
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
            'mfa_verified_user_id' => $user->id,
        ];
    }

    private function enableImpersonation(): void
    {
        config(['feature_flags.flags.admin_impersonation' => true]);
    }

    private function enrolledSuperAdmin(): User
    {
        return User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
            'google2fa_secret' => encrypt('ABCDEFGHIJKLMNOP'),
            'mfa_enabled_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiTokenLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user, string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * The auth factory caches the sanctum RequestGuard (with its resolved
     * user) for the life of the container; production resolves one container
     * per request. Reset it so each in-process test request re-validates the
     * token against the database.
     */
    private function freshGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_created_token_is_stored_hashed_and_the_plaintext_is_returned_once(): void
    {
        $user = User::factory()->create();
        $auth = $user->createToken('bootstrap', ['write']);

        $response = $this->withHeaders($this->bearer($user, $auth->plainTextToken))
            ->postJson('/api/v1/tokens', ['name' => 'mobile app']);

        $response->assertCreated();
        $plaintext = $response->json('data.token');
        $this->assertNotEmpty($plaintext, 'The plaintext token must be returned exactly once at creation.');

        $row = DB::table('personal_access_tokens')->where('name', 'mobile app')->first();
        $this->assertNotNull($row);

        // plainTextToken has the "id|secret" shape; the secret part must hash
        // to what is stored — never the plaintext itself.
        $secret = substr($plaintext, strpos($plaintext, '|') + 1);
        $this->assertNotSame($plaintext, $row->token);
        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertStringStartsWith(config('sanctum.token_prefix', ''), $secret);

        // Neither the plaintext nor its hash may leak from any read endpoint.
        foreach (['/api/v1/tokens', '/api/v1/me'] as $endpoint) {
            $body = $this->withHeaders($this->bearer($user, $auth->plainTextToken))
                ->getJson($endpoint)
                ->getContent();
            $this->assertStringNotContainsString($plaintext, $body, 'Plaintext leaked via '.$endpoint);
            $this->assertStringNotContainsString($secret, $body, 'Secret leaked via '.$endpoint);
            $this->assertStringNotContainsString($row->token, $body, 'Hash leaked via '.$endpoint);
        }
    }

    public function test_token_abilities_are_limited_to_read_and_write(): void
    {
        $user = User::factory()->create();
        $auth = $user->createToken('bootstrap', ['write']);

        $this->withHeaders($this->bearer($user, $auth->plainTextToken))
            ->postJson('/api/v1/tokens', ['name' => 'bad', 'abilities' => ['read', 'write', 'admin', '*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.2', 'abilities.3']);

        $this->withHeaders($this->bearer($user, $auth->plainTextToken))
            ->postJson('/api/v1/tokens', ['name' => 'reader'])
            ->assertCreated();

        $row = DB::table('personal_access_tokens')->where('name', 'reader')->first();
        $this->assertSame(['read'], json_decode($row->abilities, true), 'Abilities default to read-only.');
    }

    public function test_revoked_token_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $auth = $user->createToken('revoke-me', ['read', 'write']);
        $headers = $this->bearer($user, $auth->plainTextToken);

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->freshGuards();

        $tokenId = (int) substr($auth->plainTextToken, 0, (int) strpos($auth->plainTextToken, '|'));
        $this->withHeaders($headers)->deleteJson('/api/v1/tokens/'.$tokenId)->assertOk();
        $this->freshGuards();

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('id', $tokenId)->count(),
            'Revocation must delete the token row.'
        );
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_user_cannot_revoke_another_users_token(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceToken = $alice->createToken('alice-device', ['read']);
        $bobAuth = $bob->createToken('bob-device', ['write']);

        $aliceTokenId = (int) substr($aliceToken->plainTextToken, 0, (int) strpos($aliceToken->plainTextToken, '|'));

        $this->withHeaders($this->bearer($bob, $bobAuth->plainTextToken))
            ->deleteJson('/api/v1/tokens/'.$aliceTokenId)
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('id', $aliceTokenId)->count(),
            'Another user\'s token must survive the revocation attempt.'
        );
        $this->freshGuards();

        $this->withHeaders($this->bearer($alice, $aliceToken->plainTextToken))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_read_ability_cannot_use_write_endpoints(): void
    {
        $user = User::factory()->create();
        $auth = $user->createToken('readonly', ['read']);

        $this->withHeaders($this->bearer($user, $auth->plainTextToken))
            ->postJson('/api/v1/tokens', ['name' => 'escalation'])
            ->assertStatus(403, 'A read-only token must not mint tokens.');

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'escalation']);
    }

    public function test_tokens_expired_by_the_configured_lifetime_are_rejected(): void
    {
        $user = User::factory()->create();
        $auth = $user->createToken('aged', ['read']);
        $headers = $this->bearer($user, $auth->plainTextToken);

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->freshGuards();

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(401);
    }
}

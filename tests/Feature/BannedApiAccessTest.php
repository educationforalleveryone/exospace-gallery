<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BannedApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_banned_user_with_valid_token_is_blocked_on_api_and_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['ban_reason' => 'Terms violation']);
        $token = $user->createToken('mobile', ['read']);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Ban out-of-band: the ban action revokes tokens up front, but this
        // middleware must be the trust boundary that never relies on that.
        $user->forceFill(['banned_at' => now()])->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertStatus(403);
        $response->assertJsonStructure(['message']);
        $this->assertStringContainsString('Terms violation', (string) $response->json('message'));

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
            'A banned user\'s API tokens must be revoked on the blocked request.'
        );
    }

    public function test_banned_user_with_valid_token_is_blocked_on_write_endpoints_and_cannot_mint_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('ci', ['write']);

        $user->forceFill(['banned_at' => now()])->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->postJson('/api/v1/tokens', ['name' => 'sneaky']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'sneaky']);
    }

    public function test_ban_reason_is_stripped_and_truncated_in_the_api_response(): void
    {
        $user = User::factory()->create([
            'ban_reason' => '<script>alert(1)</script> '.str_repeat('x', 300),
        ]);
        $token = $user->createToken('mobile', ['read']);
        $user->forceFill(['banned_at' => now()])->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/me');

        $message = (string) $response->json('message');
        $response->assertStatus(403);
        $this->assertStringNotContainsString('<script>', $message, 'The ban reason must be escaped of tags.');
        $this->assertStringNotContainsString(
            str_repeat('x', 250),
            $message,
            'The ban reason must be truncated.'
        );
    }

    public function test_non_banned_user_is_not_affected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }
}

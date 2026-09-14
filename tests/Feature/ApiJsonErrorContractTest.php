<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiJsonErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_without_accept_header_receives_json_401(): void
    {
        $response = $this->get('/api/v1/me', ['Accept' => 'text/html']);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
        $this->assertNull($response->headers->get('Location'), 'API auth failures must not redirect.');
    }

    public function test_unauthenticated_write_attempt_without_accept_header_receives_json_401(): void
    {
        $response = $this->post('/api/v1/tokens', ['name' => 'x'], ['Accept' => 'text/html']);

        $response->assertStatus(401);
        $response->assertHeaderMissing('Location');
    }

    public function test_validation_failure_without_accept_header_receives_json_422(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('contract', ['write']);

        $response = $this->withHeaders([
            'Accept' => 'text/html',
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->post('/api/v1/tokens', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertNull($response->headers->get('Location'), 'API validation failures must not redirect.');
    }

    public function test_unknown_api_route_without_accept_header_receives_json_404(): void
    {
        $response = $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html']);

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }
}

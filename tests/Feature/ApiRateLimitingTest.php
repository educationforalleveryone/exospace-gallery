<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('qa-ingest:127.0.0.1');
        parent::tearDown();
    }

    public function test_public_api_rate_limit_returns_json_429_with_retry_after(): void
    {
        // throttle:60,1 on the public read group. The 61st request inside the
        // same window must be refused with a machine-readable envelope.
        $lastStatus = null;

        for ($i = 0; $i < 60; $i++) {
            $lastStatus = $this->getJson('/api/v1/galleries')->status();
        }

        $this->assertSame(200, $lastStatus, 'the first 60 requests must succeed');

        $response = $this->getJson('/api/v1/galleries');

        $response->assertStatus(429);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertNotNull($response->headers->get('Retry-After'), '429 must carry Retry-After.');
        $response->assertJsonStructure(['message']);
    }

    public function test_control_center_ingest_attempts_are_capped_even_when_rejected(): void
    {
        // The ingest limiter counts rejected-token attempts too, so the
        // public endpoint cannot be probed for the ingest token unboundedly.
        config()->set('test-center.ingest_token', 'secret-ingest-token');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/control-center/runs', [], ['X-QA-Token' => 'wrong-token'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/api/control-center/runs', [], ['X-QA-Token' => 'wrong-token']);

        $response->assertStatus(429);
        $response->assertJson(['message' => 'Too many ingest attempts. Retry shortly.']);
    }

    public function test_control_center_ingest_fails_closed_without_configuration(): void
    {
        // Without an ingest token configured the endpoint pretends to be absent.
        config()->set('test-center.ingest_token', '');

        $this->postJson('/api/control-center/runs')->assertStatus(404);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 1 — ingestion API security surface.
 *
 * POST /api/ops/ingest is the only UNAUTHENTICATED write path into the
 * control plane, which makes it the most security-sensitive endpoint of
 * the module. These tests pin its contract:
 *
 *   - fail-closed when no tokens are configured (404)
 *   - timing-safe token auth (401 on bad/missing token)
 *   - the token determines the application identity (no spoofing)
 *   - payload validation + size caps
 *   - server-side redaction regardless of what the reporter sends
 *   - dedup counters visible in the response
 */
class OpsIngestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Enabled with one reporting application: "project-b".
        config(['ops.ingest.tokens' => 'project-b=test-token-value-1,project-c=test-token-value-2']);
    }

    public function test_fails_closed_with_404_when_no_tokens_configured(): void
    {
        config(['ops.ingest.tokens' => null]);

        $this->postJson('/api/ops/ingest', ['title' => 'Test'])
            ->assertStatus(404);
    }

    public function test_rejects_missing_token_with_401(): void
    {
        $this->postJson('/api/ops/ingest', ['title' => 'Test'])
            ->assertStatus(401);
    }

    public function test_rejects_wrong_token_with_401(): void
    {
        $this->postJson('/api/ops/ingest', ['title' => 'Test'], ['X-Ops-Token' => 'wrong'])
            ->assertStatus(401);

        // No events may leak into the store on auth failure.
        $this->assertSame(0, OpsEvent::count());
        $this->assertSame(0, OpsApplication::where('provider', 'ingest')->count());
    }

    public function test_valid_token_creates_event_and_application(): void
    {
        $response = $this->postJson('/api/ops/ingest', [
            'title' => 'Database connection failure',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'severity' => 'error',
            'environment' => 'production',
        ], ['X-Ops-Token' => 'test-token-value-1']);

        $response->assertStatus(201);

        $event = OpsEvent::first();
        $this->assertSame('DATABASE', $event->category);
        $this->assertSame('critical', $event->severity);
        $this->assertSame('ingest', $event->source);

        // Identity comes from the TOKEN, not the payload.
        $this->assertSame('project-b', $event->application->slug);
        $this->assertSame('Project B', $event->application->name);
    }

    public function test_token_two_maps_to_second_application(): void
    {
        $this->postJson('/api/ops/ingest', [
            'title' => 'Something broke',
        ], ['X-Ops-Token' => 'test-token-value-2'])->assertStatus(201);

        $this->assertSame('project-c', OpsEvent::first()->application->slug);
    }

    public function test_validation_rejects_oversized_payloads(): void
    {
        $this->postJson('/api/ops/ingest', [
            'title' => str_repeat('x', 300),
        ], ['X-Ops-Token' => 'test-token-value-1'])->assertStatus(422);

        $this->postJson('/api/ops/ingest', [
            'title' => 'Ok title',
            'message' => str_repeat('x', 9000),
        ], ['X-Ops-Token' => 'test-token-value-1'])->assertStatus(422);

        $this->postJson('/api/ops/ingest', [
            'title' => 'Ok title',
            'context' => ['key' => str_repeat('x', 20000)],
        ], ['X-Ops-Token' => 'test-token-value-1'])->assertStatus(422);

        $this->assertSame(0, OpsEvent::count());
    }

    public function test_recurring_report_deduplicates(): void
    {
        $payload = ['title' => 'Database connection failure'];

        $first = $this->postJson('/api/ops/ingest', $payload, ['X-Ops-Token' => 'test-token-value-1']);
        $first->assertStatus(201);

        $second = $this->postJson('/api/ops/ingest', $payload, ['X-Ops-Token' => 'test-token-value-1']);
        $second->assertStatus(200); // not 201 — existing event updated
        $this->assertSame(2, $second->json('occurrence_count'));

        $this->assertSame(1, OpsEvent::count());
    }

    public function test_reporter_cannot_spoof_another_application(): void
    {
        // Even if the payload claims to be "self", attribution follows the
        // token (project-b) — identity is non-negotiable.
        config(['ops.ingest.tokens' => 'project-b=test-token-value-1']);

        $this->postJson('/api/ops/ingest', [
            'title' => 'Attempted spoof',
            'context' => ['application' => 'self', 'slug' => 'self'],
        ], ['X-Ops-Token' => 'test-token-value-1'])->assertStatus(201);

        $this->assertSame('project-b', OpsEvent::first()->application->slug);
    }

    public function test_server_side_redaction_ignores_reporter_payloads(): void
    {
        $this->postJson('/api/ops/ingest', [
            'title' => 'Auth failed with api_key',
            'message' => 'Login admin@exospace.gallery password hunter2',
            'context' => ['api_key' => 'super-secret', 'nested' => ['token' => 'leak-me']],
        ], ['X-Ops-Token' => 'test-token-value-1'])->assertStatus(201);

        $event = OpsEvent::first();
        $raw = json_encode([$event->title, $event->message, $event->context]);

        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertStringNotContainsString('leak-me', $raw);
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('admin@exospace.gallery', $raw);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 1 — the ingestion pipeline.
 *
 * Covers: deduplication (the storm-of-37 = one row contract), episode
 * reset on reopen, redaction at the pipeline level, and classification
 * enrichment — the four behaviors every source depends on.
 */
class OpsEventIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function ingestor(): OpsEventIngestor
    {
        return app(OpsEventIngestor::class);
    }

    public function test_first_ingest_creates_event_with_classification(): void
    {
        $event = $this->ingestor()->record([
            'source' => 'app_log',
            'severity' => 'error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $this->assertNotNull($event);
        $this->assertSame('DATABASE', $event->category);
        $this->assertSame('critical', $event->severity); // pattern floor upgraded
        $this->assertSame(1, $event->occurrence_count);
        $this->assertSame('open', $event->status);
        $this->assertNotEmpty($event->classification['likely_causes']);

        // Attribution defaults to the self application.
        $this->assertTrue($event->application->is_self);
    }

    public function test_recurring_error_deduplicates_into_one_row(): void
    {
        for ($i = 0; $i < 37; $i++) {
            $this->ingestor()->record([
                'source' => 'app_log',
                'severity' => 'error',
                'message' => 'SQLSTATE[HY000] [2002] Connection refused',
            ]);
        }

        $this->assertSame(1, OpsEvent::count());
        $event = OpsEvent::first();
        $this->assertSame(37, $event->occurrence_count);
        $this->assertSame(37, $event->total_count);
    }

    public function test_different_numbers_group_into_same_event(): void
    {
        // "Failed for order 12345" and "order 99999" are ONE operational
        // problem — normalization must group them.
        $this->ingestor()->record(['source' => 'app_log', 'severity' => 'error',
            'message' => 'Payment failed for order 12345']);
        $this->ingestor()->record(['source' => 'app_log', 'severity' => 'error',
            'message' => 'Payment failed for order 99999']);

        $this->assertSame(1, OpsEvent::count());
        $this->assertSame(2, OpsEvent::first()->occurrence_count);
    }

    public function test_different_messages_same_title_dont_explode(): void
    {
        // Title (classifier-driven) drives the fingerprint, so the same
        // problem with different messages groups together.
        $this->ingestor()->record(['source' => 'app_log', 'severity' => 'error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused']);
        $this->ingestor()->record(['source' => 'app_log', 'severity' => 'error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused on host A']);

        $this->assertSame(1, OpsEvent::count());
    }

    public function test_resolved_event_reopens_with_fresh_episode(): void
    {
        $event = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $event->update(['status' => 'resolved', 'resolved_at' => now()]);

        $reopened = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'error',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $this->assertSame($event->id, $reopened->id);
        $this->assertSame('open', $reopened->status);
        $this->assertNull($reopened->resolved_at);
        $this->assertSame(1, $reopened->occurrence_count); // fresh episode
        $this->assertSame(2, $reopened->total_count);      // all-time kept
    }

    public function test_redaction_applies_at_pipeline_level(): void
    {
        $event = $this->ingestor()->record([
            'source' => 'app_log',
            'severity' => 'error',
            'message' => 'Login failed for admin@exospace.gallery with password hunter2',
            'context' => [
                'password' => 'hunter2',
                'url' => 'https://exospace.gallery/login',
            ],
        ]);

        $this->assertStringNotContainsString('admin@exospace.gallery', $event->message);
        $this->assertStringNotContainsString('hunter2', $event->message);
        $this->assertSame('[REDACTED]', $event->context['password']);
        $this->assertSame('https://exospace.gallery/login', $event->context['url']);
    }

    public function test_ingest_event_attributes_to_token_application(): void
    {
        $app = $this->ingestor()->resolveOrCreateApplication('project-b', 'Project B');

        $event = $this->ingestor()->record([
            'source' => 'ingest',
            'severity' => 'error',
            'title' => 'Redis connection failure on Project B',
            'message' => 'Connection to Redis failed',
            'application_id' => $app->id,
        ]);

        $this->assertSame('project-b', $event->application->slug);
        $this->assertSame('REDIS', $event->category);
    }

    public function test_severity_never_deescalates_within_episode(): void
    {
        $event = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'critical',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        // A later occurrence logged at 'warning' must not soften the event.
        $event = $this->ingestor()->record([
            'source' => 'app_log', 'severity' => 'warning',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $this->assertSame('critical', $event->severity);
    }
}

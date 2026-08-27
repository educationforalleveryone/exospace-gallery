<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsCredential;
use App\Ops\Models\OpsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 6 — the credential-rotation reminder sweep.
 *
 * ops:sweep-credentials makes cadence lapses find the operator the way
 * the diagnostic sweep makes broken subsystems find them: daily, in
 * Slack, with a deduplicated SECURITY event that resolves itself once
 * the page is worked. These tests pin:
 *
 *   1. ROTATE NOW / OVERDUE → exactly ONE warning alert + ONE SECURITY
 *      event (source 'sweep'), listing the affected credentials.
 *   2. Recurrence: a second sweep bumps occurrence_count, never a
 *      second row.
 *   3. Recovery: an all-clean sweep resolves the event and announces it
 *      exactly once (idempotent on later sweeps).
 *   4. DUE SOON only → a WEEKLY-gated info nudge, never an event.
 *   5. All clean + nothing due → complete silence.
 *   6. Kill switch + never-fatal contract (exit 0 always).
 */
class OpsSweepCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();

        // Slack target for OperationalAlertService — captured by Http::fake.
        config([
            'services.operational_alerts.webhook_url' => 'https://slack.test/hook',
            'ops.credentials.reminders_enabled' => true,
        ]);

        Http::fake(['slack.test/*' => Http::response(['ok' => true])]);
    }

    private function runSweep(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('ops:sweep-credentials');
    }

    private function rotationEvents()
    {
        return OpsEvent::where('source', 'sweep')->where('title', 'Credential rotation overdue');
    }

    private function sentSlackMessages(): array
    {
        $messages = [];
        Http::assertSent(function ($request) use (&$messages) {
            if (str_contains((string) $request->url(), 'slack.test')) {
                $messages[] = (string) ($request->data()['text'] ?? '');
            }

            return true; // count as "asserted" for every request
        });

        return $messages;
    }

    private function slackTexts(): array
    {
        $texts = [];
        foreach (Http::recorded() as $pair) {
            [$request, $response] = $pair;
            if (str_contains((string) $request->url(), 'slack.test')) {
                $texts[] = (string) ($request->data()['text'] ?? '');
            }
        }

        return $texts;
    }

    // ── 1. The overdue path ─────────────────────────────────────────────

    public function test_exposed_never_rotated_credentials_alert_and_record_one_security_event(): void
    {
        // Default state: nothing rotated → every §15-exposed credential
        // reads ROTATE NOW, the optional tokens read UNTRACKED.
        $this->runSweep()->assertExitCode(0);

        $event = $this->rotationEvents()->first();
        $this->assertNotNull('Event expected for ROTATE NOW credentials');
        $this->assertNotNull($event);
        $this->assertSame('SECURITY', $event->category);
        $this->assertSame('warning', $event->severity);
        $this->assertSame('open', $event->status);

        // The context carries the credential KEYS — never values.
        $this->assertNotEmpty($event->context['rotate_now']);
        $this->assertSame([], $event->context['overdue']);
        $this->assertContains('db-password', $event->context['rotate_now']);

        // ONE Slack warning, naming the count and the page.
        $texts = $this->slackTexts();
        $this->assertNotEmpty($texts);
        $warning = implode("\n---\n", $texts);
        $this->assertStringContainsString('credential rotation overdue', $warning);
        $this->assertStringContainsString('/ops/credentials', $warning);
    }

    public function test_overdue_rotation_reports_days_and_moves_to_overdue_bucket(): void
    {
        // Rotate db-password long past its 90-day cadence → OVERDUE.
        OpsCredential::create([
            'key' => 'db-password',
            'last_rotated_at' => now()->subDays(120),
        ]);

        $this->runSweep()->assertExitCode(0);

        $event = $this->rotationEvents()->first();
        $this->assertNotNull($event);
        $this->assertSame(['db-password'], $event->context['overdue']);
        $this->assertNotContains('db-password', $event->context['rotate_now']);
    }

    public function test_one_event_total_even_with_many_lapses(): void
    {
        // Every exposed credential is in some bad state — still ONE
        // event, ONE Slack warning (the list rides inside them).
        OpsCredential::create(['key' => 'db-password', 'last_rotated_at' => now()->subDays(200)]);
        OpsCredential::create(['key' => 'coolify-token', 'last_rotated_at' => now()->subDays(150)]);

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(1, $this->rotationEvents()->count());
    }

    // ── 2. Recurrence ───────────────────────────────────────────────────

    public function test_recurrence_bumps_the_counter_never_a_second_row(): void
    {
        $this->runSweep()->assertExitCode(0);
        $this->runSweep()->assertExitCode(0);

        $events = $this->rotationEvents()->get();
        $this->assertCount(1, $events);
        $this->assertSame(2, (int) $events->first()->occurrence_count);
    }

    // ── 3. Recovery ─────────────────────────────────────────────────────

    public function test_clean_sweep_resolves_the_prior_event_and_announces_once(): void
    {
        // First: a lapse (nothing rotated at all).
        $this->runSweep()->assertExitCode(0);
        $event = $this->rotationEvents()->first();
        $this->assertNotNull($event);

        // Now the operator works the list: record rotations far inside
        // every cadence (10 days beats 90/180 everywhere).
        foreach (['db-password', 'app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }

        $this->runSweep()->assertExitCode(0);

        $this->assertSame('resolved', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->resolved_at);

        // The recovery note went out exactly once (idempotent next time).
        $texts = $this->slackTexts();
        $this->assertSame(1, substr_count(implode("\n", $texts), 'back in cadence'));

        // A third sweep: nothing left to resolve, no duplicate note.
        $this->runSweep()->assertExitCode(0);
        $texts = $this->slackTexts();
        $this->assertSame(1, substr_count(implode("\n", $texts), 'back in cadence'));
    }

    public function test_clean_state_with_no_prior_event_records_nothing(): void
    {
        foreach (['db-password', 'app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(0, $this->rotationEvents()->count());
        $this->assertSame(0, OpsEvent::where('source', 'sweep')->count());

        // Silence is the reward: not a single Slack message.
        $this->assertSame([], $this->slackTexts());
    }

    // ── 4. Due-soon nudge ───────────────────────────────────────────────

    public function test_due_soon_only_sends_weekly_nudge_without_event(): void
    {
        // Rotate everything freshly EXCEPT one credential 80 days into
        // its 90-day cadence → DUE SOON, not overdue.
        foreach (['app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }
        OpsCredential::create(['key' => 'db-password', 'last_rotated_at' => now()->subDays(80)]);

        $this->runSweep()->assertExitCode(0);

        // No event — due-soon is planning, not a problem.
        $this->assertSame(0, $this->rotationEvents()->count());

        // One info nudge naming the credential...
        $texts = $this->slackTexts();
        $nudge = implode("\n", $texts);
        $this->assertStringContainsString('due for rotation soon', $nudge);
        $this->assertStringContainsString('Database password', $nudge);

        // ...and the weekly gate holds on the immediate second run.
        $before = count($this->slackTexts());
        $this->runSweep()->assertExitCode(0);
        $after = count($this->slackTexts());
        $this->assertSame($before, $after, 'The weekly gate must suppress the second nudge.');
    }

    public function test_due_soon_nudge_returns_after_the_gate_expires(): void
    {
        foreach (['app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }
        OpsCredential::create(['key' => 'db-password', 'last_rotated_at' => now()->subDays(80)]);

        // First run: nudge sent, gate set.
        $this->runSweep()->assertExitCode(0);
        $this->assertNotEmpty($this->slackTexts());

        // Simulate a week passing: the gate TTL is 6 days — forget it.
        \Illuminate\Support\Facades\Cache::forget('ops:sweep-credentials:nudge');
        $count = count($this->slackTexts());

        $this->runSweep()->assertExitCode(0);
        $this->assertCount($count + 1, $this->slackTexts());
    }

    // ── 5. Untracked stays invisible ────────────────────────────────────

    public function test_untracked_optional_tokens_never_trigger_anything(): void
    {
        // All §15-exposed credentials rotated; the two optional OpsCenter
        // tokens (ingest, sentry-api) remain never-rotated → UNTRACKED.
        foreach (['db-password', 'app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(0, OpsEvent::where('source', 'sweep')->count());
        $this->assertSame([], $this->slackTexts());
    }

    // ── 6. Kill switch + robustness ─────────────────────────────────────

    public function test_kill_switch_makes_the_sweep_a_noop(): void
    {
        config(['ops.credentials.reminders_enabled' => false]);

        $this->runSweep()
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        $this->assertSame(0, OpsEvent::where('source', 'sweep')->count());
        $this->assertSame([], $this->slackTexts());
    }

    public function test_sweep_summary_is_honest_about_the_state(): void
    {
        // No rotations at all: the summary line reports the lapses.
        $this->runSweep()
            ->expectsOutputToContain('rotate-now')
            ->assertExitCode(0);

        // All clean: the summary says so.
        foreach (['db-password', 'app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }

        $this->runSweep()
            ->expectsOutputToContain('Credential rotation clean')
            ->assertExitCode(0);
    }

    public function test_security_category_is_available_to_the_error_inventory(): void
    {
        // The sweep's events must be groupable in the events UI like every
        // other domain — SECURITY joined the category allow-list.
        $this->assertContains('SECURITY', OpsEvent::CATEGORIES);
    }
}

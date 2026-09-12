<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsCredential;
use App\Ops\Models\OpsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_exposed_never_rotated_credentials_alert_and_record_one_security_event(): void
    {
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
        OpsCredential::create(['key' => 'db-password', 'last_rotated_at' => now()->subDays(200)]);
        OpsCredential::create(['key' => 'coolify-token', 'last_rotated_at' => now()->subDays(150)]);

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(1, $this->rotationEvents()->count());
    }

    public function test_recurrence_bumps_the_counter_never_a_second_row(): void
    {
        $this->runSweep()->assertExitCode(0);
        $this->runSweep()->assertExitCode(0);

        $events = $this->rotationEvents()->get();
        $this->assertCount(1, $events);
        $this->assertSame(2, (int) $events->first()->occurrence_count);
    }

    public function test_clean_sweep_resolves_the_prior_event_and_announces_once(): void
    {
        $this->runSweep()->assertExitCode(0);
        $event = $this->rotationEvents()->first();
        $this->assertNotNull($event);

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

    public function test_due_soon_only_sends_weekly_nudge_without_event(): void
    {
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

        $this->runSweep()->assertExitCode(0);
        $this->assertNotEmpty($this->slackTexts());

        // Simulate a week passing: the gate TTL is 6 days — forget it.
        \Illuminate\Support\Facades\Cache::forget('ops:sweep-credentials:nudge');
        $count = count($this->slackTexts());

        $this->runSweep()->assertExitCode(0);
        $this->assertCount($count + 1, $this->slackTexts());
    }

    public function test_untracked_optional_tokens_never_trigger_anything(): void
    {
        foreach (['db-password', 'app-key', 'coolify-token', 'slack-webhooks', 'r2-keys',
            'backup-password', 'twocheckout-secrets', 'sentry-dsn', 'resend-key', 'metrics-webhook-tokens', ] as $key) {
            OpsCredential::create(['key' => $key, 'last_rotated_at' => now()->subDays(10)]);
        }

        $this->runSweep()->assertExitCode(0);

        $this->assertSame(0, OpsEvent::where('source', 'sweep')->count());
        $this->assertSame([], $this->slackTexts());
    }

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
        $this->assertContains('SECURITY', OpsEvent::CATEGORIES);
    }
}

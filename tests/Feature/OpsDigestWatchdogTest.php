<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsMorningDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsDigestWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();

        config([
            'services.operational_alerts.webhook_url' => 'https://slack.test/hook',
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.digest.enabled' => true,
            'ops.digest.watchdog_enabled' => true,
        ]);

        Cache::flush();

        Http::fake([
            'slack.test/*' => Http::response(['ok' => true]),
        ]);
    }

    private function digest(): OpsMorningDigestService
    {
        return app(OpsMorningDigestService::class);
    }

    private function stampToday(string $trigger = 'scheduled'): void
    {
        Cache::put('ops:morning-digest:last', ['at' => now(), 'trigger' => $trigger], now()->addDays(7));
    }

    private function slackMessages(): array
    {
        $messages = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains((string) $request->url(), 'slack.test')) {
                $messages[] = (string) ($request->data()['text'] ?? '');
            }
        }

        return $messages;
    }

    public function test_healthy_delivery_is_silent(): void
    {
        $this->stampToday();

        $this->artisan('ops:check-digest-delivery')
            ->expectsOutputToContain('watchdog quiet')
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->slackMessages()), 'A healthy morning must not generate watchdog noise.');
        $this->assertSame(0, OpsEvent::where('source', 'watchdog')->count());
    }

    public function test_manual_send_this_morning_counts_as_delivered(): void
    {
        $this->stampToday('manual');

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $this->assertSame([], array_filter($this->slackMessages()));
    }

    public function test_healthy_run_resolves_a_prior_miss_with_one_recovery_note(): void
    {
        // A watchdog event left open from a previous missed morning.
        $event = OpsEvent::create([
            'fingerprint' => sha1('watchdog-miss'),
            'source' => 'watchdog',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'warning',
            'title' => 'Digest watchdog: morning digest missing',
            'status' => 'open',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $this->stampToday();

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $this->assertSame('resolved', $event->fresh()->status, 'The prior watchdog event must auto-resolve on recovery.');

        $messages = $this->slackMessages();
        $this->assertCount(1, $messages, 'Exactly ONE recovery note — not a daily OK message.');
        $this->assertStringContainsString('recovered', $messages[0]);
    }

    public function test_second_healthy_run_stays_silent_after_resolving(): void
    {
        OpsEvent::create([
            'fingerprint' => sha1('watchdog-miss-2'),
            'source' => 'watchdog',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'warning',
            'title' => 'Digest watchdog: morning digest missing',
            'status' => 'open',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $this->stampToday();

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();
        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $this->assertCount(1, $this->slackMessages(), 'The recovery note fires exactly once per miss.');
    }

    public function test_missing_stamp_raises_one_alert_and_one_event(): void
    {
        // No stamp at all — never sent.
        $this->artisan('ops:check-digest-delivery')
            ->expectsOutputToContain('MISSED')
            ->assertSuccessful();

        $messages = $this->slackMessages();
        $this->assertCount(1, $messages, 'One missed morning = ONE watchdog alert.');
        $this->assertStringContainsString('ever been recorded', $messages[0], 'The never-sent variant must say so honestly.');

        $events = OpsEvent::where('source', 'watchdog')->get();
        $this->assertCount(1, $events, 'One missed morning = ONE watchdog event (ingestor dedup aside, the command records once).');
        $this->assertSame('INFRASTRUCTURE', $events[0]->category);
        $this->assertSame('warning', $events[0]->severity);
        $this->assertSame('Digest watchdog: morning digest missing', $events[0]->title);
    }

    public function test_stale_stamp_from_yesterday_is_a_miss(): void
    {
        Cache::put('ops:morning-digest:last', ['at' => now()->subDay(), 'trigger' => 'scheduled'], now()->addDays(7));

        $this->artisan('ops:check-digest-delivery')
            ->expectsOutputToContain('MISSED')
            ->assertSuccessful();

        $messages = $this->slackMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('nothing arrived this morning', $messages[0], 'The stale variant must say the last send predates today.');

        $this->assertSame(1, OpsEvent::where('source', 'watchdog')->count());
    }

    public function test_the_miss_alert_carries_actionable_next_steps(): void
    {
        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $messages = $this->slackMessages();
        $this->assertStringContainsString('scheduler', $messages[0]);
        $this->assertStringContainsString('OPERATIONAL_ALERT_WEBHOOK', $messages[0]);
        $this->assertStringContainsString('/ops/digest', $messages[0]);
    }

    public function test_digest_disabled_is_a_clean_no_op(): void
    {
        config(['ops.digest.enabled' => false]);

        $this->artisan('ops:check-digest-delivery')
            ->expectsOutputToContain('suspended')
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->slackMessages()));
        $this->assertSame(0, OpsEvent::where('source', 'watchdog')->count());
    }

    public function test_watchdog_disabled_is_a_clean_no_op(): void
    {
        config(['ops.digest.watchdog_enabled' => false]);

        // No stamp + digest enabled — but the watchdog itself is off.
        $this->artisan('ops:check-digest-delivery')
            ->expectsOutputToContain('watchdog disabled')
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->slackMessages()));
        $this->assertSame(0, OpsEvent::where('source', 'watchdog')->count());
    }

    public function test_recovery_after_a_cache_flush_resolves_via_title_lookup(): void
    {
        $this->artisan('ops:check-digest-delivery')->assertSuccessful(); // the miss
        $this->assertSame(1, OpsEvent::where('source', 'watchdog')->where('status', 'open')->count());

        // A cache flush wipes the cached event id AND the digest stamp.
        Cache::flush();
        $this->stampToday();

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $this->assertSame(
            0,
            OpsEvent::where('source', 'watchdog')->where('status', 'open')->count(),
            'The stable-title fallback must find and resolve the event even without the cached id.',
        );
    }

    public function test_the_schedule_registers_the_watchdog_daily_at_0845(): void
    {
        $events = collect(\Illuminate\Support\Facades\Schedule::events());

        $watchdog = $events->first(fn ($event) => str_contains((string) $event->command, 'ops:check-digest-delivery'));

        $this->assertNotNull($watchdog, 'The watchdog must be registered on the schedule.');
        // dailyAt('08:45') compiles to cron: minute 45, hour 8, every day.
        $this->assertSame('45 8 * * *', (string) $watchdog->expression, 'It must run 30 minutes after the 08:15 digest.');
    }
}

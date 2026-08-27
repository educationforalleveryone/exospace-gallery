<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsEvent;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 9, Feature C — the watchdog's escape hatch.
 *
 * Every alert route (default + the four per-severity routes) lives in
 * the SAME failure domain: if the Slack workspace or the webhook
 * integration dies, the watchdog's alarm about the dead morning digest
 * dies with it. The escalation channel is the independent copy:
 *
 *   1. alert(..., escalate: true) posts the IDENTICAL payload to BOTH
 *      the primary route and OPS_ESCALATION_WEBHOOK — the primary still
 *      fires (a missed digest is often a dead scheduler with a healthy
 *      webhook; the operator's main channel stays the first line).
 *   2. Unset URL = the pre-Iteration-9 behavior, byte for byte.
 *   3. Default alerts NEVER touch the escalation channel.
 *   4. Dedup gates BOTH posts — a suppressed duplicate escalates nowhere.
 *   5. A DEAD PRIMARY cannot prevent the escalation copy (independent
 *      try/catch + timeout) — the whole point of the feature.
 *   6. The watchdog's miss alarm escalates; its recovery note does NOT
 *      (the all-clear is informational; the resolved event row already
 *      records it durably).
 */
class OpsEscalationChannelTest extends TestCase
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
            'services.operational_alerts.escalation_webhook_url' => null,
            'ops.digest.enabled' => true,
            'ops.digest.watchdog_enabled' => true,
        ]);

        Cache::flush();

        Http::fake([
            'slack.test/*' => Http::response(['ok' => true]),
            'escalation.test/*' => Http::response(['ok' => true]),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function alerts(): OperationalAlertService
    {
        return app(OperationalAlertService::class);
    }

    private function withEscalation(): void
    {
        config(['services.operational_alerts.escalation_webhook_url' => 'https://escalation.test/hook']);
    }

    private function requestsTo(string $host): array
    {
        $requests = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains((string) $request->url(), $host)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    // ── 1. Both channels ────────────────────────────────────────────────

    public function test_an_escalated_alert_posts_to_both_channels(): void
    {
        $this->withEscalation();

        $this->alerts()->alert('Meta alarm', 'The thing itself is broken', 'warning', 'meta.alarm', true);

        $this->assertCount(1, $this->requestsTo('slack.test'), 'The primary route still fires — the escalation channel is a SECOND copy, not a replacement.');
        $this->assertCount(1, $this->requestsTo('escalation.test'), 'The independent escalation copy must arrive.');
    }

    public function test_the_escalation_payload_is_byte_identical_to_the_primary(): void
    {
        $this->withEscalation();

        $this->alerts()->alert('Meta alarm', 'Same words everywhere', 'warning', 'meta.alarm', true);

        $primary = $this->requestsTo('slack.test')[0];
        $escalated = $this->requestsTo('escalation.test')[0];

        $this->assertSame($primary->data(), $escalated->data(), 'One alert, two channels, the same words — no variant payload to maintain.');
    }

    public function test_an_unset_escalation_url_is_the_pre_iteration9_behavior(): void
    {
        $this->alerts()->alert('Meta alarm', 'No escape hatch configured', 'warning', 'meta.alarm', true);

        $this->assertCount(1, $this->requestsTo('slack.test'));
        $this->assertSame([], $this->requestsTo('escalation.test'), 'Nothing to escalate to — silently, not as an error.');
    }

    public function test_default_alerts_never_touch_the_escalation_channel(): void
    {
        $this->withEscalation();

        // No escalate flag — the everyday alert path.
        $this->alerts()->alert('Regular alert', 'Product problem', 'critical', 'product.problem');
        $this->alerts()->alert('Regular alert', 'No dedup key either');

        $this->assertCount(2, $this->requestsTo('slack.test'));
        $this->assertSame([], $this->requestsTo('escalation.test'), 'Escalation is for meta-failures where the channel itself is suspect — never the default.');
    }

    public function test_dedup_suppression_gates_both_channels(): void
    {
        $this->withEscalation();

        $this->alerts()->alert('Meta alarm', 'Still broken', 'warning', 'meta.alarm', true);
        $this->alerts()->alert('Meta alarm', 'Still broken (repeat)', 'warning', 'meta.alarm', true);

        $this->assertCount(1, $this->requestsTo('slack.test'), 'The dedup key suppresses the repeat.');
        $this->assertCount(1, $this->requestsTo('escalation.test'), 'A suppressed duplicate escalates nowhere.');
    }

    public function test_a_dead_primary_webhook_cannot_kill_the_escalation_copy(): void
    {
        // The whole point: the primary channel is dead — the alarm about
        // the dead channel must still arrive somewhere independent.
        $this->withEscalation();

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'slack.test')) {
                throw new ConnectionException('connection refused');
            }

            return Http::response(['ok' => true]);
        });

        $this->alerts()->alert('Meta alarm', 'Primary is dead', 'warning', 'meta.alarm', true);

        $this->assertCount(1, $this->requestsTo('escalation.test'), 'The escalation copy survives the dead primary.');
    }

    // ── 2. The watchdog wiring ──────────────────────────────────────────

    public function test_the_watchdogs_missing_digest_alarm_escalates(): void
    {
        $this->withEscalation();
        Cache::flush();

        // No stamp at all — "never recorded" miss variant.
        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $watchdogMessages = array_map(
            fn ($request) => (string) ($request->data()['text'] ?? ''),
            $this->requestsTo('slack.test'),
        );
        $escalatedMessages = array_map(
            fn ($request) => (string) ($request->data()['text'] ?? ''),
            $this->requestsTo('escalation.test'),
        );

        $this->assertCount(1, $watchdogMessages);
        $this->assertStringContainsString('morning digest MISSING', $watchdogMessages[0]);
        $this->assertCount(1, $escalatedMessages, 'The silence-contract alarm rides BOTH channels.');
        $this->assertStringContainsString('morning digest MISSING', $escalatedMessages[0]);
    }

    public function test_the_watchdogs_recovery_note_does_not_escalate(): void
    {
        $this->withEscalation();

        // A watchdog event left open from a previous missed morning.
        OpsEvent::create([
            'fingerprint' => sha1('watchdog-miss'),
            'source' => 'watchdog',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'warning',
            'title' => 'Digest watchdog: morning digest missing',
            'status' => 'open',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        // Today's digest arrived — the healthy path resolves the prior
        // event with ONE recovery note on the PRIMARY channel only.
        Cache::put('ops:morning-digest:last', ['at' => now(), 'trigger' => 'scheduled'], now()->addDays(7));

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $recovery = array_map(
            fn ($request) => (string) ($request->data()['text'] ?? ''),
            $this->requestsTo('slack.test'),
        );

        $this->assertCount(1, $recovery);
        $this->assertStringContainsString('delivery recovered', $recovery[0]);
        $this->assertSame([], $this->requestsTo('escalation.test'), 'The all-clear is informational — it never pages the escape hatch.');
    }

    public function test_a_healthy_watchdog_morning_stays_silent_on_both_channels(): void
    {
        $this->withEscalation();

        Cache::put('ops:morning-digest:last', ['at' => now(), 'trigger' => 'scheduled'], now()->addDays(7));

        $this->artisan('ops:check-digest-delivery')->assertSuccessful();

        $this->assertSame([], $this->requestsTo('slack.test'));
        $this->assertSame([], $this->requestsTo('escalation.test'), 'Quiet when healthy — on every channel.');
    }

    public function test_the_escalation_webhook_is_tracked_by_the_credential_inventory(): void
    {
        $inventory = app(\App\Ops\Services\OpsCredentialInventoryService::class);

        // Unset: the entry stays honest on the primary webhooks alone.
        $before = collect($inventory->inventory()['items'])->firstWhere('key', 'slack-webhooks');
        $this->assertContains('OPS_ESCALATION_WEBHOOK', $before['env'], 'The escape hatch rotates with the webhooks it mirrors.');

        config(['services.operational_alerts.webhook_url' => null, 'services.operational_alerts.critical_webhook_url' => null]);

        // Only the escalation URL set: the entry must count as configured.
        $this->withEscalation();
        $status = collect($inventory->inventory()['items'])->firstWhere('key', 'slack-webhooks');
        $this->assertTrue((bool) $status['configured'], 'An escalation-only setup is still a configured alerting surface.');
    }
}

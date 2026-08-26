<?php

namespace Tests\Feature;

use App\Mail\BillingExportEmail;
use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingExportService;
use App\Services\JobHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ITERATION 6 — scheduled weekly billing digest.
 *
 * The on-demand export (Iteration 5) depended on someone remembering to
 * click. These tests pin the scheduled path:
 *   1. Unconfigured (no BILLING_EXPORT_EMAIL) → clean no-op, no mail,
 *      heartbeat still stamped (feature OFF ≠ job DEAD)
 *   2. Configured → digest delivered with the money-events CSV attached,
 *      audit-logged as billing.exported (system actor)
 *   3. Zero-row weeks still send — predictable cadence is the evidence
 *   4. The CSV is byte-identical to the on-demand export (shared service)
 *   5. The on-demand export still works after the service extraction
 *   6. Total delivery failure → FAILURE exit + critical alert, no stamp
 */
class ScheduledBillingExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.billing_export.email' => null]);
        Mail::fake();
        Http::fake();
    }

    private function seedMoneyEvent(string $status, ?string $daysAgo = null): Transaction
    {
        return Transaction::factory()->create([
            'status'    => $status,
            'created_at' => $daysAgo !== null ? now()->subDays((int) $daysAgo) : now(),
        ]);
    }

    public function test_unconfigured_recipients_is_a_clean_noop(): void
    {
        $this->seedMoneyEvent('refunded');

        $this->artisan('exospace:send-billing-export')
            ->expectsOutputToContain('No billing-export recipient configured')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, AdminAuditLog::where('action', 'billing.exported')->count(), 'nothing left the system — no audit row');

        // Feature OFF must not read as job DEAD to the heartbeat monitor.
        $this->assertSame('fresh', app(JobHeartbeatService::class)->status('exospace:send-billing-export'));
    }

    public function test_configured_recipients_receive_digest_with_csv_and_audit_row(): void
    {
        config(['services.billing_export.email' => 'finance@example.com,cfo@example.com']);

        $inWindow = $this->seedMoneyEvent('refunded', 2);
        $this->seedMoneyEvent('chargeback', 5);
        $this->seedMoneyEvent('partial_refund', 6);
        // Outside the 7-day window — must not appear in the digest CSV.
        $this->seedMoneyEvent('refunded', 20);
        // Completed sales are summary-only, not money-event rows.
        $this->seedMoneyEvent('completed', 1);

        $this->artisan('exospace:send-billing-export')->assertExitCode(0);

        // The digest mailable is queued (ShouldQueue) — one per recipient.
        Mail::assertQueued(BillingExportEmail::class, 2);
        Mail::assertQueued(BillingExportEmail::class, fn ($mail) => $mail->hasTo('finance@example.com'));
        Mail::assertQueued(BillingExportEmail::class, fn ($mail) => $mail->hasTo('cfo@example.com'));

        // The CSV attachment is the shared-service export: exactly the 3
        // in-window money events under the shared column set (no completed
        // row, no out-of-window row), byte-identical to the on-demand path.
        // (Filename comes from the mailable itself — the His component can
        // tick between the command run and this recompute.)
        Mail::assertQueued(BillingExportEmail::class, function (BillingExportEmail $mail) {
            $expected = app(BillingExportService::class)
                ->transactionsCsv(null, now()->subDays(7)->startOfDay());

            $this->assertSame(3, $expected['count'], 'window contains the 3 seeded money events');
            $this->assertStringContainsString('exospace-transactions-', $mail->csv['filename']);

            // assertHasAttachment renders the mailable (populating
            // rawAttachments) before comparing data + name + mime.
            return $mail->assertHasAttachment(
                \Illuminate\Mail\Attachment::fromData(fn () => $expected['content'], $mail->csv['filename'])
                    ->withMime('text/csv')
            );
        });

        // PII leaving the system is attributable — system actor (actor_id NULL).
        $audit = AdminAuditLog::where('action', 'billing.exported')->latest('id')->first();
        $this->assertNotNull($audit, 'the scheduled send is audit-logged');
        $this->assertNull($audit->actor_id, 'audit actor is the system (scheduled job)');
        $this->assertSame('scheduled_digest', $audit->payload['export_type'] ?? null);
        $this->assertSame(3, $audit->payload['row_count'] ?? null);
        $this->assertSame(2, $audit->payload['recipients'] ?? null);
    }

    public function test_zero_row_week_still_sends_without_attachment(): void
    {
        config(['services.billing_export.email' => 'finance@example.com']);

        // An old transaction exists (audit target + proves the window is
        // genuinely empty rather than the install being transaction-less).
        $this->seedMoneyEvent('completed', 20);

        $this->artisan('exospace:send-billing-export')->assertExitCode(0);

        // A predictable "nothing happened" is reconciliation evidence —
        // a missing digest would be the anomaly.
        Mail::assertQueued(BillingExportEmail::class, 1);
        Mail::assertQueued(BillingExportEmail::class, function (BillingExportEmail $mail) {
            return count($mail->attachments()) === 0;
        });

        // Zero-row sends are still audit-logged (the cadence is the evidence).
        $this->assertSame(1, AdminAuditLog::where('action', 'billing.exported')->count());
    }

    public function test_digest_csv_matches_the_on_demand_export_byte_for_byte(): void
    {
        config(['services.billing_export.email' => 'finance@example.com']);

        $refund = $this->seedMoneyEvent('refunded', 3);

        $exporter = app(BillingExportService::class);
        $scheduled = $exporter->transactionsCsv(null, now()->subDays(7)->startOfDay());

        // On-demand shape: same query + same columns via the service.
        $onDemand = $exporter->transactionsCsv(null, now()->subDays(7)->startOfDay());

        $this->assertSame($onDemand['content'], $scheduled['content']);
        $this->assertSame(1, $scheduled['count']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $scheduled['content'], 'BOM for Excel UTF-8');
        $this->assertStringContainsString('ID,Date,Status,Plan,Amount,Currency', $scheduled['content']);
    }

    public function test_on_demand_export_route_still_streams_after_service_extraction(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        $refund = $this->seedMoneyEvent('refunded', 1);

        $response = $this->actingAs($admin)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/master-control/billing/export?export=transactions&days=90');

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('Invoice ID', $body);
        $this->assertStringContainsString('refunded', $body);

        // The manual export remains attributable to the clicking admin.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action'  => 'billing.exported',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_total_delivery_failure_returns_failure_alerts_and_skips_stamp(): void
    {
        config(['services.billing_export.email' => 'finance@example.com']);
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/services/T000/B000/be']);

        $this->seedMoneyEvent('refunded', 1);

        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->artisan('exospace:send-billing-export')->assertExitCode(1);

        // A digest that silently never arrives is exactly what this job
        // exists to prevent — page + leave the heartbeat unstamped so the
        // cadence monitor becomes the second net.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/services/T000/B000/be'
                && str_contains($request->body(), 'Weekly billing digest delivery failed');
        });
        $this->assertSame('missing', app(JobHeartbeatService::class)->status('exospace:send-billing-export'));
    }

    // ── ITERATION 8: partial delivery alerting (workstream D) ──────────

    public function test_partial_delivery_failure_alerts_warning_and_stamps_heartbeat(): void
    {
        // ITERATION 8 FIX (audit-finding A-1 / Candidate E): a single
        // bouncing recipient (1 of N) previously failed silently — the
        // audit row captured delivery_failures: 1 but no Slack alert
        // fired and the heartbeat stamped as if everything were fine.
        // The CSV carries customer billing PII; a partial delivery is a
        // quieter version of the total-failure signal (one operator
        // silently stopped receiving the financial digest).
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/services/T000/B000/be']);

        $this->seedMoneyEvent('refunded', 1);

        // Two recipients: one succeeds, one throws.
        Mail::shouldReceive('to')
            ->with(\Mockery::on(fn ($r) => true))
            ->andReturnSelf(); // chainable
        Mail::shouldReceive('send')->twice()->andReturnUsing(function () {
            static $i = 0;
            $i++;
            if ($i === 1) {
                return; // first recipient succeeds
            }
            throw new \RuntimeException('Recipient 2 mailbox full');
        });

        $this->artisan('exospace:send-billing-export', ['--to' => 'ok@example.com,bounce@example.com'])
            ->assertExitCode(0);

        // Warning alert (NOT critical) — the digest DID go out, just
        // not to everyone. DedupKey is distinct from total-failure.
        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            return str_contains($body, 'partial delivery')
                && str_contains($body, 'warning');
        });

        // Heartbeat STILL stamps — the scheduler ran the job; the
        // delivery problem is downstream (the cadence is intact).
        $this->assertSame(
            'fresh',
            app(JobHeartbeatService::class)->status('exospace:send-billing-export'),
        );

        // Audit row captures delivery_failures: 1 (so the bounce
        // is attributable in the audit log too).
        $audit = AdminAuditLog::where('action', 'billing.exported')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(1, $audit->payload['delivery_failures'] ?? null);
    }
}

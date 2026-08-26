<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ITERATION 5 — billing review CSV export.
 *
 * The billing review data could previously only leave the system by
 * pagination + copy-paste. These tests pin:
 *   1. Transactions export: BOM + header + rows, money-status filter
 *      semantics shared with the page, 90-day default window, days=all
 *   2. Webhook ledger export with the failed-only filter
 *   3. Every export writes an admin audit record (the CSV carries PII out)
 *   4. Access: super-admin + MFA only (403 otherwise)
 */
class BillingExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actingAsMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function makeTransaction(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id'         => User::factory()->create()->id,
            'invoice_id'      => 'INV-' . uniqid(),
            'plan'            => 'pro',
            'amount'          => 29.00,
            'currency'        => 'USD',
            'customer_email'  => 'buyer@example.com',
            'customer_name'   => 'Test Buyer',
            'status'          => 'refunded',
        ], $attrs));
    }

    // ── Transactions export ─────────────────────────────────────────────

    public function test_transactions_export_streams_csv_with_bom_and_rows(): void
    {
        $tx = $this->makeTransaction(['status' => 'refunded', 'amount' => 29.00]);

        $response = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export?export=transactions');

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename=exospace-transactions-', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();

        // Excel-safe UTF-8 BOM (same convention as the GDPR self-export).
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Invoice ID', $csv);
        $this->assertStringContainsString($tx->invoice_id, $csv);
        $this->assertStringContainsString('buyer@example.com', $csv);
    }

    public function test_transactions_export_defaults_to_money_statuses_only(): void
    {
        $this->makeTransaction(['status' => 'refunded']);
        $this->makeTransaction(['status' => 'completed']);
        $this->makeTransaction(['status' => 'chargeback']);

        $csv = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export')
            ->streamedContent();

        // Default = refunds & chargebacks (the page's default filter), not
        // every purchase ever.
        $this->assertSame(2, substr_count($csv, "\n") - 1, 'two money-event rows (header excluded)');
    }

    public function test_transactions_export_respects_status_filter(): void
    {
        $this->makeTransaction(['status' => 'refunded']);
        $completed = $this->makeTransaction(['status' => 'completed']);

        $csv = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export?export=transactions&status=completed')
            ->streamedContent();

        $this->assertStringContainsString($completed->invoice_id, $csv);
        $this->assertStringNotContainsString('refunded', $csv);
    }

    public function test_transactions_export_defaults_to_90_day_window_and_days_all_lifts_it(): void
    {
        $old = $this->makeTransaction(['status' => 'refunded']);
        $old->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();
        $this->makeTransaction(['status' => 'refunded']);

        $windowed = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export')
            ->streamedContent();
        $this->assertStringNotContainsString($old->invoice_id, $windowed, '120-day-old row outside the default 90-day window');

        $all = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export?days=all')
            ->streamedContent();
        $this->assertStringContainsString($old->invoice_id, $all, 'days=all exports everything');
    }

    // ── Webhook ledger export ───────────────────────────────────────────

    public function test_webhooks_export_streams_the_ledger_with_failed_filter(): void
    {
        $failed = ProcessedWebhook::create([
            'message_id'   => 'MSG-FAIL-' . uniqid(),
            'message_type' => 'REFUND_ISSUED',
            'invoice_id'   => 'INV-FAIL',
            'payload'      => ['message_type' => 'REFUND_ISSUED'],
            'status'       => 'failed',
            'updated_at'   => now(),
        ]);
        ProcessedWebhook::create([
            'message_id'   => 'MSG-OK-' . uniqid(),
            'message_type' => 'ORDER_CREATED',
            'invoice_id'   => 'INV-OK',
            'payload'      => ['message_type' => 'ORDER_CREATED'],
            'status'       => 'processed',
            'processed_at' => now(),
            'updated_at'   => now(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()
            ->get('/master-control/billing/export?export=webhooks&webhook_status=failed');

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename=exospace-webhooks-', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($failed->message_id, $csv);
        $this->assertStringContainsString('Message Type', $csv);
        $this->assertStringNotContainsString('MSG-OK-', $csv, 'failed-only filter respected');
    }

    // ── Audit trail + access control ────────────────────────────────────

    public function test_every_export_writes_an_admin_audit_record(): void
    {
        $this->makeTransaction(['status' => 'refunded']);

        $this->actingAsMfaSuperAdmin()->get('/master-control/billing/export')->assertOk();

        $log = AdminAuditLog::where('action', 'billing.exported')->first();

        $this->assertNotNull($log, 'billing data leaving the system is attributable');
        $this->assertSame('transactions', $log->payload['export_type']);
        $this->assertSame(1, $log->payload['row_count']);
        $this->assertSame('90', (string) $log->payload['days']);
    }

    public function test_regular_users_cannot_export_billing_data(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/master-control/billing/export')->assertStatus(403);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/master-control/billing/export')->assertRedirect('/login');
    }
}

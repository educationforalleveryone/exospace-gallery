<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ITERATION 4 — webhook ledger + billing review + replay tooling.
 *
 * Covers the three Iteration-4 billing-truth mechanisms:
 *   1. Payload persistence at ingress (processed_webhooks is now a ledger,
 *      not a delete-on-failure marker)
 *   2. Status lifecycle: processing → processed / failed; failed and
 *      stuck-processing rows are claimable (2CO retry semantics preserved
 *      from Iteration 1, but the evidence survives)
 *   3. Webhook.* admin audit records + the super-admin Billing Review
 *      page with password.confirm-gated replay
 *   4. 90-day ledger retention (GDPR bound on stored PII)
 */
class WebhookLedgerAndReplayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_WORD = 'ledger-secret-word';
    private const PRODUCT_ID_PRO = 'PRO-1001';
    private const VENDOR_ID = 'V12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('services.2checkout.secret_word', self::SECRET_WORD);
        Config::set('services.2checkout.account_number', 'ACC-001');
        Config::set('services.2checkout.product_id_pro', self::PRODUCT_ID_PRO);
        Config::set('services.2checkout.product_id_studio', 'STUDIO-2001');
        Config::set('services.2checkout.buy_link_secret_word', null);
        Config::set('services.2checkout.allow_md5_only', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function validIpnPayload(array $overrides = []): array
    {
        $saleId = $overrides['sale_id'] ?? 'SALE-' . uniqid();
        $invoiceId = $overrides['invoice_id'] ?? 'INV-' . uniqid();
        $vendorId = $overrides['vendor_id'] ?? self::VENDOR_ID;

        $stringToHash = strlen($saleId) . $saleId
                      . strlen($vendorId) . $vendorId
                      . strlen($invoiceId) . $invoiceId
                      . strlen(self::SECRET_WORD) . self::SECRET_WORD;

        return array_merge([
            'message_type'      => 'ORDER_CREATED',
            'message_id'        => 'MSG-' . uniqid(),
            'sale_id'           => $saleId,
            'vendor_id'         => $vendorId,
            'invoice_id'        => $invoiceId,
            'md5_hash'          => strtoupper(md5($stringToHash)),
            'customer_email'    => 'buyer@example.com',
            'customer_name'     => 'Test Buyer',
            'item_id_1'         => self::PRODUCT_ID_PRO,
            'item_list_amount_1'=> '29.00',
            'list_currency'     => 'USD',
        ], $overrides);
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/webhooks/2checkout', $payload);
    }

    private function ledgerRow(string $messageId, string $messageType = 'ORDER_CREATED'): ?object
    {
        return DB::table('processed_webhooks')
            ->where('message_id', $messageId)
            ->where('message_type', $messageType)
            ->first();
    }

    private function superAdminMfaSession(): array
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return [
            'user' => $admin,
            'acting' => fn () => $this->actingAs($admin)->withSession([
                'mfa_verified'    => true,
                'mfa_verified_at' => now()->timestamp,
                // password.confirm middleware: confirmed recently enough
                'auth.password_confirmed_at' => now()->timestamp,
            ]),
        ];
    }

    // ── 1. Payload persistence ───────────────────────────────────────────

    public function test_webhook_payload_is_persisted_and_marked_processed(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload();

        $this->postWebhook($payload)->assertOk();

        $row = $this->ledgerRow($payload['message_id']);
        $this->assertNotNull($row, 'ledger row must exist');
        $this->assertSame('processed', $row->status);
        $this->assertSame('ORDER_CREATED', $row->message_type);
        $this->assertSame($payload['invoice_id'], $row->invoice_id);

        $stored = json_decode((string) $row->payload, true);
        $this->assertSame('buyer@example.com', $stored['customer_email']);
        $this->assertSame($payload['invoice_id'], $stored['invoice_id']);
    }

    public function test_duplicate_message_is_still_skipped(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload();

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk(); // dedupe

        $this->assertSame(1, Transaction::where('invoice_id', $payload['invoice_id'])->count());
        $this->assertSame('processed', $this->ledgerRow($payload['message_id'])->status);
    }

    // ── 2. Status lifecycle ──────────────────────────────────────────────

    public function test_failed_webhook_row_is_marked_failed_and_payload_survives(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload();

        // Force the upgrade transaction to throw after the ledger row is
        // inserted (User::updating fires inside DB::transaction inside the
        // lock — exactly the crash window Iteration 1 handled by deleting
        // the marker and Iteration 4 handles by marking the row failed).
        User::updating(function () {
            throw new \RuntimeException('simulated transaction crash');
        });

        $this->postWebhook($payload)->assertStatus(500);

        $row = $this->ledgerRow($payload['message_id']);
        $this->assertNotNull($row, 'row must NOT be deleted on failure (Iteration-4 evidence retention)');
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->payload, 'payload must survive the failure for replay');

        $this->assertSame(0, Transaction::where('invoice_id', $payload['invoice_id'])->count());
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_failed_webhook_is_reprocessed_by_2co_retry(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload();

        User::updating(function () {
            throw new \RuntimeException('simulated transaction crash');
        });
        $this->postWebhook($payload)->assertStatus(500);

        // 2CO retries the exact same message once the transient fault clears.
        User::flushEventListeners(); // drop the crashing updating listener

        $this->postWebhook($payload)->assertOk();

        $row = $this->ledgerRow($payload['message_id']);
        $this->assertSame('processed', $row->status, 'failed row must be claimable by the retry');
        $this->assertSame(1, Transaction::where('invoice_id', $payload['invoice_id'])->count());
        $this->assertSame('pro', User::where('email', 'buyer@example.com')->first()->plan);
    }

    public function test_stale_processing_row_is_claimable_but_fresh_one_is_not(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload();

        // Simulate a crashed worker: row stuck in 'processing' for 11 min.
        DB::table('processed_webhooks')->insert([
            'message_id'   => $payload['message_id'],
            'message_type' => 'ORDER_CREATED',
            'invoice_id'   => $payload['invoice_id'],
            'status'       => 'processing',
            'processed_at' => now()->subMinutes(11),
            'updated_at'   => now()->subMinutes(11),
        ]);

        $this->postWebhook($payload)->assertOk();
        $this->assertSame('processed', $this->ledgerRow($payload['message_id'])->status);
        $this->assertSame(1, Transaction::where('invoice_id', $payload['invoice_id'])->count());

        // Fresh 'processing' row = an in-flight worker owns it: duplicate.
        $payload2 = $this->validIpnPayload();
        User::where('email', 'buyer@example.com')->update(['plan' => 'free']);
        DB::table('processed_webhooks')->insert([
            'message_id'   => $payload2['message_id'],
            'message_type' => 'ORDER_CREATED',
            'invoice_id'   => $payload2['invoice_id'],
            'status'       => 'processing',
            'processed_at' => now(),
            'updated_at'   => now(),
        ]);

        $this->postWebhook($payload2)->assertOk();
        $this->assertSame(0, Transaction::where('invoice_id', $payload2['invoice_id'])->count(), 'fresh processing row must block (in-flight worker owns it)');
    }

    // ── 3. Audit records ─────────────────────────────────────────────────

    public function test_refund_webhook_writes_system_actor_audit_record(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com', 'plan' => 'pro']);
        $payload = $this->validIpnPayload();
        Transaction::create([
            'user_id'        => $user->id,
            'invoice_id'     => $payload['invoice_id'],
            'plan'           => 'pro',
            'amount'         => 29.00,
            'currency'       => 'USD',
            'customer_email' => $user->email,
            'status'         => 'completed',
        ]);

        $refund = $this->validIpnPayload([
            'message_type'      => 'REFUND_ISSUED',
            'invoice_id'        => $payload['invoice_id'],
            'item_list_amount_1'=> '29.00',
        ]);

        $this->postWebhook($refund)->assertOk();

        $audit = AdminAuditLog::where('action', 'webhook.refund_applied')->first();
        $this->assertNotNull($audit, 'refund must be audited');
        $this->assertNull($audit->actor_id, 'webhook records use the system (null) actor');
        $this->assertSame($user->id, $audit->target_id);
        $this->assertSame('refunded', $audit->payload['new_status'] ?? null);
        $this->assertSame($payload['invoice_id'], $audit->payload['invoice_id'] ?? null);
    }

    public function test_webhook_audit_actions_never_email_super_admins(): void
    {
        // The SendSuperAdminActionAlert whitelist must NOT include the new
        // webhook.* actions — recurring renewals would spam every super-admin.
        $listener = new \App\Listeners\SendSuperAdminActionAlert();
        $source = file_get_contents((new \ReflectionClass($listener))->getFileName());

        $this->assertStringNotContainsString("'webhook.", $source,
            'webhook.* audit actions must stay off the destructive-action email whitelist');
    }

    // ── 4. Billing Review page ───────────────────────────────────────────

    public function test_billing_review_requires_super_admin(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->get('/master-control/billing')->assertForbidden();
    }

    public function test_billing_review_rejects_guests(): void
    {
        // Separate test — actingAs() from a prior assertion in the same
        // test would leak into this request.
        $this->get('/master-control/billing')->assertRedirect('/login');
    }

    public function test_super_admin_sees_transactions_and_ledger(): void
    {
        $session = $this->superAdminMfaSession();
        $buyer = User::factory()->create(['plan' => 'pro']);
        Transaction::create([
            'user_id'        => $buyer->id,
            'invoice_id'     => 'INV-VISIBLE-1',
            'plan'           => 'pro',
            'amount'         => 29.00,
            'currency'       => 'USD',
            'customer_email' => $buyer->email,
            'status'         => 'refunded',
        ]);
        ProcessedWebhook::create([
            'message_id'   => 'MSG-REVIEW-1',
            'message_type' => 'REFUND_ISSUED',
            'invoice_id'   => 'INV-VISIBLE-1',
            'payload'      => json_encode(['invoice_id' => 'INV-VISIBLE-1', 'customer_email' => $buyer->email]),
            'status'       => 'processed',
            'processed_at' => now(),
        ]);

        $response = ($session['acting'])()->get('/master-control/billing');

        $response->assertOk()
            ->assertSee('Billing Review')
            ->assertSee('INV-VISIBLE-1')
            ->assertSee('REFUND_ISSUED')
            ->assertSee('Refunded');
    }

    public function test_billing_review_defaults_to_money_events_and_supports_filters(): void
    {
        $session = $this->superAdminMfaSession();
        $buyer = User::factory()->create();
        foreach ([['completed', 'INV-C-1'], ['refunded', 'INV-R-1']] as [$status, $invoice]) {
            Transaction::create([
                'user_id'        => $buyer->id,
                'invoice_id'     => $invoice,
                'plan'           => 'pro',
                'amount'         => 29.00,
                'currency'       => 'USD',
                'customer_email' => $buyer->email,
                'status'         => $status,
            ]);
        }

        $page = ($session['acting'])()->get('/master-control/billing');
        $page->assertOk()->assertSee('INV-R-1');
        $this->assertStringNotContainsString('INV-C-1', $page->getContent(), 'completed purchases stay off the default money-events view');

        $all = ($session['acting'])()->get('/master-control/billing?status=completed');
        $all->assertOk()->assertSee('INV-C-1');
    }

    // ── 5. Replay ────────────────────────────────────────────────────────

    public function test_replay_requires_password_confirmation(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true, 'email_verified_at' => now(),
        ]);

        $row = ProcessedWebhook::create([
            'message_id' => 'MSG-RP-1', 'message_type' => 'ORDER_CREATED', 'invoice_id' => 'INV-RP-1',
            'payload' => json_encode(['message_type' => 'ORDER_CREATED']), 'status' => 'failed',
            'processed_at' => now(),
        ]);

        // MFA verified but password NOT recently confirmed:
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post("/master-control/billing/webhooks/{$row->id}/replay")
            ->assertRedirect(route('password.confirm'));
    }

    public function test_super_admin_can_replay_a_failed_refund_webhook(): void
    {
        $session = $this->superAdminMfaSession();
        $admin = $session['user'];

        $buyer = User::factory()->create(['plan' => 'pro']);
        Transaction::create([
            'user_id'        => $buyer->id,
            'invoice_id'     => 'INV-REPLAY-1',
            'plan'           => 'pro',
            'amount'         => 29.00,
            'currency'       => 'USD',
            'customer_email' => $buyer->email,
            'status'         => 'completed',
        ]);

        // The payload exactly as 2CO sent it (signature-valid at original
        // ingress; replay re-runs processing without re-verification).
        $saleId = 'SALE-REPLAY-1';
        $invoiceId = 'INV-REPLAY-1';
        $stringToHash = strlen($saleId) . $saleId . strlen(self::VENDOR_ID) . self::VENDOR_ID
                      . strlen($invoiceId) . $invoiceId . strlen(self::SECRET_WORD) . self::SECRET_WORD;

        $row = ProcessedWebhook::create([
            'message_id'   => 'MSG-REPLAY-1',
            'message_type' => 'REFUND_ISSUED',
            'invoice_id'   => $invoiceId,
            'payload'      => json_encode([
                'message_type'      => 'REFUND_ISSUED',
                'message_id'        => 'MSG-REPLAY-1',
                'sale_id'           => $saleId,
                'vendor_id'         => self::VENDOR_ID,
                'invoice_id'        => $invoiceId,
                'md5_hash'          => strtoupper(md5($stringToHash)),
                'customer_email'    => $buyer->email,
                'item_list_amount_1'=> '29.00',
                'list_currency'     => 'USD',
            ]),
            'status'       => 'failed',
            'processed_at' => now(),
        ]);

        $response = ($session['acting'])()
            ->from('/master-control/billing')
            ->post("/master-control/billing/webhooks/{$row->id}/replay");

        $response->assertRedirect('/master-control/billing');
        $response->assertSessionHas('success');

        $row->refresh();
        $this->assertSame('processed', $row->status);
        $this->assertSame(1, $row->replay_count);
        $this->assertNotNull($row->last_replayed_at);

        // The replay actually re-processed the refund:
        $this->assertSame('refunded', Transaction::where('invoice_id', $invoiceId)->value('status'));

        // And the replay itself is audited with the ADMIN as actor:
        $audit = AdminAuditLog::where('action', 'webhook.replayed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('REFUND_ISSUED', $audit->payload['message_type'] ?? null);
    }

    public function test_replay_without_stored_payload_is_rejected_cleanly(): void
    {
        $session = $this->superAdminMfaSession();

        $row = ProcessedWebhook::create([
            'message_id' => 'MSG-NOPAYLOAD', 'message_type' => 'ORDER_CREATED', 'invoice_id' => 'INV-NP-1',
            'payload' => null, 'status' => 'failed', 'processed_at' => now(),
        ]);

        $response = ($session['acting'])()
            ->from('/master-control/billing')
            ->post("/master-control/billing/webhooks/{$row->id}/replay");

        $response->assertRedirect('/master-control/billing');
        $response->assertSessionHas('error');
        $this->assertSame(0, $row->fresh()->replay_count);
    }

    // ── 6. Retention ─────────────────────────────────────────────────────

    public function test_cleanup_prunes_webhook_ledger_rows_beyond_90_days(): void
    {
        $old = ProcessedWebhook::create([
            'message_id' => 'MSG-OLD', 'message_type' => 'ORDER_CREATED', 'invoice_id' => 'INV-OLD',
            'payload' => json_encode(['a' => 1]), 'status' => 'processed',
            'processed_at' => now()->subDays(91),
        ]);
        $new = ProcessedWebhook::create([
            'message_id' => 'MSG-NEW', 'message_type' => 'ORDER_CREATED', 'invoice_id' => 'INV-NEW',
            'payload' => json_encode(['a' => 1]), 'status' => 'processed',
            'processed_at' => now()->subDays(30),
        ]);

        Artisan::call('exospace:cleanup-stale');

        $this->assertNull($old->fresh(), 'rows older than 90 days are pruned (GDPR bound on stored PII)');
        $this->assertNotNull($new->fresh(), 'rows inside the retention window survive');
    }
}

<?php

namespace Tests\Feature;

use App\Models\PendingUpgrade;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Webhook + billing pipeline tests.
 *
 * (Task H15) — covers the most money-critical path in the app:
 *   - 2Checkout IPN signature verification (MD5 + optional HMAC SHA-256)
 *   - ORDER_CREATED → user upgrade
 *   - Idempotency (duplicate invoice_id → no double-upgrade)
 *   - REFUND_ISSUED → downgrade (only if current plan matches)
 *   - CHARGEBACK_REPORTED → downgrade
 *   - CHARGEBACK_REVERSED → plan restored
 *   - external-reference matching (pending_upgrade token)
 *   - customer_email fallback matching
 *
 * These tests use the LEGACY MD5 signature (which 2Checkout always sends)
 * and do NOT require the optional HMAC SHA-256 layer. The HMAC layer is
 * env-gated and tested separately.
 */
class WebhookBillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_WORD = 'test-secret-word';
    private const PRODUCT_ID_PRO = 'PRO-1001';
    private const PRODUCT_ID_STUDIO = 'STUDIO-2001';
    private const VENDOR_ID = 'V12345';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.2checkout.secret_word', self::SECRET_WORD);
        Config::set('services.2checkout.account_number', 'ACC-001');
        Config::set('services.2checkout.product_id_pro', self::PRODUCT_ID_PRO);
        Config::set('services.2checkout.product_id_studio', self::PRODUCT_ID_STUDIO);
        Config::set('services.2checkout.buy_link_secret_word', null); // disable HMAC layer
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Build a valid 2Checkout IPN payload + MD5 hash.
     */
    private function validIpnPayload(array $overrides = []): array
    {
        $saleId = $overrides['sale_id'] ?? 'SALE-' . uniqid();
        $invoiceId = $overrides['invoice_id'] ?? 'INV-' . uniqid();
        $vendorId = $overrides['vendor_id'] ?? self::VENDOR_ID;

        $stringToHash = strlen($saleId) . $saleId
                      . strlen($vendorId) . $vendorId
                      . strlen($invoiceId) . $invoiceId
                      . strlen(self::SECRET_WORD) . self::SECRET_WORD;
        $hash = strtoupper(md5($stringToHash));

        return array_merge([
            'message_type'      => 'ORDER_CREATED',
            'sale_id'           => $saleId,
            'vendor_id'         => $vendorId,
            'invoice_id'        => $invoiceId,
            'md5_hash'          => $hash,
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

    // ── ORDER_CREATED: upgrade ───────────────────────────────────────────

    public function test_order_created_upgrades_user_to_pro(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        $this->assertNull($user->plan_expires_at); // lifetime
        $this->assertDatabaseHas('transactions', [
            'user_id'   => $user->id,
            'invoice_id'=> $payload['invoice_id'],
            'plan'      => 'pro',
            'status'    => 'completed',
        ]);
    }

    public function test_order_created_upgrades_user_to_studio(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_STUDIO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan);
    }

    public function test_order_created_with_external_reference_matches_by_token(): void
    {
        $user = User::factory()->create(['email' => 'account@example.com']);
        $pending = PendingUpgrade::createForUser($user, 'pro', self::PRODUCT_ID_PRO);

        $payload = $this->validIpnPayload([
            'customer_email'    => 'different-paypal@example.com', // doesn't match account email
            'external-reference'=> $pending->token,
            'item_id_1'         => self::PRODUCT_ID_PRO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan);

        // The pending upgrade should be marked as converted
        $pending->refresh();
        $this->assertEquals('converted', $pending->status);
        $this->assertNotNull($pending->transaction_id);
    }

    public function test_order_created_with_unknown_product_id_returns_200_but_no_upgrade(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => 'UNKNOWN-PRODUCT-9999',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_order_created_for_unknown_user_returns_200(): void
    {
        $payload = $this->validIpnPayload([
            'customer_email' => 'nonexistent@example.com',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk(); // 200 so 2Checkout doesn't retry
        $this->assertDatabaseCount('transactions', 0);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_duplicate_invoice_id_does_not_double_upgrade(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload();

        // First webhook — should upgrade
        $this->postWebhook($payload);
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        $firstPlanStartedAt = $user->plan_started_at;

        // Second webhook with same invoice_id — should be idempotent
        $this->postWebhook($payload);
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        // plan_started_at should NOT be re-stamped
        $this->assertEquals($firstPlanStartedAt, $user->plan_started_at);

        // Only one transaction row
        $this->assertDatabaseCount('transactions', 1);
    }

    // ── Signature verification ───────────────────────────────────────────

    public function test_invalid_hash_returns_403(): void
    {
        $payload = $this->validIpnPayload();
        $payload['md5_hash'] = 'INVALIDHASH123';

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
    }

    public function test_missing_hash_returns_403(): void
    {
        $payload = $this->validIpnPayload();
        unset($payload['md5_hash']);

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
    }

    // ── REFUND_ISSUED ────────────────────────────────────────────────────

    public function test_refund_issued_downgrades_user_when_plan_matches(): void
    {
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-001',
            'plan'      => 'pro',
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'REFUND_ISSUED',
            'invoice_id'    => 'INV-REFUND-001',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan);

        $transaction->refresh();
        $this->assertEquals('refunded', $transaction->status);
    }

    public function test_refund_issued_does_not_downgrade_when_plan_changed(): void
    {
        // User bought Pro, then upgraded to Studio. Refunding the old Pro
        // purchase should NOT downgrade them from Studio.
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-002',
            'plan'      => 'pro', // the refunded transaction was for Pro
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'REFUND_ISSUED',
            'invoice_id'    => 'INV-REFUND-002',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan); // still Studio

        $transaction->refresh();
        $this->assertEquals('refunded', $transaction->status); // but the Pro tx is marked refunded
    }

    public function test_duplicate_refund_is_idempotent(): void
    {
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);
        Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-003',
            'plan'      => 'pro',
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'REFUND_ISSUED',
            'invoice_id'    => 'INV-REFUND-003',
        ]);

        $this->postWebhook($payload);
        $this->postWebhook($payload); // duplicate

        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertDatabaseCount('transactions', 1);
    }

    // ── CHARGEBACK_REPORTED ──────────────────────────────────────────────

    public function test_chargeback_reported_downgrades_user(): void
    {
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-001',
            'plan'      => 'studio',
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'CHARGEBACK_REPORTED',
            'invoice_id'    => 'INV-CB-001',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan);

        $transaction->refresh();
        $this->assertEquals('chargeback', $transaction->status);
    }

    // ── CHARGEBACK_REVERSED ──────────────────────────────────────────────

    public function test_chargeback_reversed_restores_plan(): void
    {
        $user = User::factory()->create([
            'email' => 'buyer@example.com',
            'plan'  => 'free', // was downgraded by chargeback
        ]);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-002',
            'plan'      => 'pro',
            'status'    => 'chargeback',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'CHARGEBACK_REVERSED',
            'invoice_id'    => 'INV-CB-002',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // restored

        $transaction->refresh();
        $this->assertEquals('completed', $transaction->status);
    }

    // ── Non-mutating message types ───────────────────────────────────────

    public function test_refund_requested_does_not_downgrade(): void
    {
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'message_type'  => 'REFUND_REQUESTED',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // unchanged
    }

    public function test_fraud_status_changed_does_not_mutate(): void
    {
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'message_type'  => 'FRAUD_STATUS_CHANGED',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // unchanged
    }

    // ── CSRF exemption ───────────────────────────────────────────────────

    public function test_webhook_is_exempt_from_csrf(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload(['item_id_1' => self::PRODUCT_ID_PRO]);

        // POST without CSRF token — should NOT return 419
        $response = $this->postJson('/webhooks/2checkout', $payload);
        $response->assertOk();
    }
}

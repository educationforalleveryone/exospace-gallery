<?php

namespace Tests\Feature;

use App\Models\PendingUpgrade;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebhookBillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_WORD = 'test-secret-word';
    private const BUY_LINK_SECRET = 'test-buy-link-secret';
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
        Config::set('services.2checkout.buy_link_secret_word', null);
    }

    private function validIpnPayload(array $overrides = []): array
    {
        $saleId = $overrides['sale_id'] ?? 'SALE-' . uniqid();
        $invoiceId = $overrides['invoice_id'] ?? 'INV-' . uniqid();
        $vendorId = $overrides['vendor_id'] ?? self::VENDOR_ID;

        $stringToHash = strtoupper(md5($saleId))
                      . $vendorId
                      . $invoiceId
                      . self::SECRET_WORD;
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

    private function signPayloadHmac(array $payload): string
    {
        $fields = [
            'sale_id', 'vendor_id', 'invoice_id', 'message_type', 'message_id',
            'customer_email', 'customer_name', 'item_count',
            'item_id_1', 'item_name_1', 'item_usd_amount_1', 'item_list_amount_1',
            'item_cust_amount_1', 'item_type_1', 'list_currency', 'cust_currency',
        ];

        $hmacPayload = '';
        foreach ($fields as $field) {
            $value = (string) ($payload[$field] ?? '');
            $hmacPayload .= strlen($value) . $value;
        }

        return hash_hmac('sha256', $hmacPayload, self::BUY_LINK_SECRET);
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/webhooks/2checkout', $payload);
    }

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
            // AUDIT-P1-8.1: Use the plaintext_token runtime attribute (not the stored hash)
            'external-reference'=> $pending->plaintext_token,
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

    public function test_duplicate_invoice_id_does_not_double_upgrade(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload();

        $this->postWebhook($payload);
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        $firstPlanStartedAt = $user->plan_started_at;

        $this->postWebhook($payload);
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        // plan_started_at should NOT be re-stamped
        $this->assertEquals($firstPlanStartedAt, $user->plan_started_at);

        // Only one transaction row
        $this->assertDatabaseCount('transactions', 1);
    }

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

    public function test_refund_issued_downgrades_user_when_plan_matches(): void
    {
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-001',
            'plan'      => 'pro',
            'amount'    => 29.00, // P1-4: explicit amount for partial-refund logic
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-001',
            'item_list_amount_1' => '29.00', // P1-4: full refund amount
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
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-002',
            'plan'      => 'pro', // the refunded transaction was for Pro
            'amount'    => 29.00, // P1-4: explicit amount
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-002',
            'item_list_amount_1' => '29.00', // P1-4: full refund amount
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
            'amount'    => 29.00, // P1-4: explicit amount
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-003',
            'item_list_amount_1' => '29.00', // P1-4: full refund amount
        ]);

        $this->postWebhook($payload);
        $this->postWebhook($payload); // duplicate

        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertDatabaseCount('transactions', 1);
    }

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

    public function test_webhook_is_exempt_from_csrf(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $payload = $this->validIpnPayload(['item_id_1' => self::PRODUCT_ID_PRO]);

        // POST without CSRF token — should NOT return 419
        $response = $this->postJson('/webhooks/2checkout', $payload);
        $response->assertOk();
    }

    public function test_hmac_signature_verifies_when_configured(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);
        $payload['signature'] = $this->signPayloadHmac($payload);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    public function test_hmac_signature_failure_returns_403(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);
        $payload['signature'] = 'tampered-signature-that-does-not-match';

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // NOT upgraded
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_valid_md5_webhook_is_accepted_without_signature_even_when_hmac_secret_configured(): void
    {
        // 2Checkout INS does not send a `signature` field — a valid md5_hash
        // must be accepted even when the buy-link secret happens to be set.
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);
        // No 'signature' field, exactly like a real INS message

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // upgraded via the provider mechanism
    }

    public function test_production_accepts_valid_md5_webhook(): void
    {
        app()['env'] = 'production';

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // upgraded via the documented INS mechanism
    }

    public function test_forged_webhook_without_valid_md5_is_rejected_in_production(): void
    {
        app()['env'] = 'production';

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);
        $payload['md5_hash'] = str_repeat('0', 32); // forged hash

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // NOT upgraded — fail-closed
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_missing_md5_hash_is_rejected_in_production(): void
    {
        app()['env'] = 'production';

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);
        unset($payload['md5_hash']);

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
        $user->refresh();
        $this->assertEquals('free', $user->plan);
    }

    public function test_tampered_customer_email_is_rejected_by_hmac(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $attacker = User::factory()->create(['email' => 'attacker@example.com']);
        $victim   = User::factory()->create(['email' => 'victim@example.com']);

        $payload = $this->validIpnPayload([
            'customer_email' => 'attacker@example.com',
            'item_id_1'      => self::PRODUCT_ID_STUDIO,
        ]);
        // Sign with the ORIGINAL customer_email
        $payload['signature'] = $this->signPayloadHmac($payload);

        $payload['customer_email'] = 'victim@example.com';

        $response = $this->postWebhook($payload);

        // HMAC must fail because customer_email was changed after signing
        $response->assertForbidden();

        $victim->refresh();
        $this->assertEquals('free', $victim->plan); // victim NOT upgraded
        $this->assertDatabaseMissing('transactions', ['user_id' => $victim->id]);
    }

    public function test_tampered_item_id_is_rejected_by_hmac(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO, // paid for Pro ($29)
        ]);
        $payload['signature'] = $this->signPayloadHmac($payload);

        // Tamper: change item_id to Studio ($99) — attacker gets Studio for Pro's price
        $payload['item_id_1'] = self::PRODUCT_ID_STUDIO;

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // NOT upgraded
    }

    public function test_tampered_message_type_is_rejected_by_hmac(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);
        Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-TAMPER-001',
            'plan'      => 'pro',
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type' => 'ORDER_CREATED',
            'invoice_id'   => 'INV-TAMPER-001',
        ]);
        $payload['signature'] = $this->signPayloadHmac($payload);

        // Tamper: change message_type to REFUND_ISSUED
        $payload['message_type'] = 'REFUND_ISSUED';

        $response = $this->postWebhook($payload);

        $response->assertForbidden();
        $user->refresh();
        $this->assertEquals('pro', $user->plan); // NOT downgraded
    }

    // ── P1-4: Partial refund does not downgrade ──────────────────────────

    public function test_partial_refund_does_not_downgrade_user(): void
    {
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-PARTIAL-001',
            'plan'      => 'studio',
            'amount'    => 99.00,
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-PARTIAL-001',
            'item_list_amount_1' => '5.00', // only $5 refunded (5% of $99)
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan); // NOT downgraded

        $transaction->refresh();
        $this->assertEquals('partial_refund', $transaction->status);
    }

    public function test_full_refund_downgrades_user(): void
    {
        // Full refund ($99 on a $99 purchase) → downgrade.
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-FULL-001',
            'plan'      => 'studio',
            'amount'    => 99.00,
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-FULL-001',
            'item_list_amount_1' => '99.00', // full refund
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // downgraded

        $transaction->refresh();
        $this->assertEquals('refunded', $transaction->status);
    }

    public function test_near_full_refund_downgrades_user(): void
    {
        // 90% refund ($89.10 on $99) → downgrade (threshold is >=90%).
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-REFUND-90-001',
            'plan'      => 'studio',
            'amount'    => 99.00,
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'       => 'REFUND_ISSUED',
            'invoice_id'         => 'INV-REFUND-90-001',
            'item_list_amount_1' => '89.10', // exactly 90%
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // downgraded (>=90%)
    }

    public function test_chargeback_does_not_downgrade_when_plan_changed(): void
    {
        $user = User::factory()->studio()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-PLAN-MISMATCH',
            'plan'      => 'pro', // the charged-back transaction was for Pro
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'CHARGEBACK_REPORTED',
            'invoice_id'    => 'INV-CB-PLAN-MISMATCH',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan); // still Studio

        $transaction->refresh();
        $this->assertEquals('chargeback', $transaction->status); // tx still marked chargeback
    }

    public function test_chargeback_downgrades_when_plan_matches(): void
    {
        // User is on Pro, chargeback on their Pro purchase → downgrade.
        $user = User::factory()->pro()->create(['email' => 'buyer@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-PLAN-MATCH',
            'plan'      => 'pro',
            'status'    => 'completed',
        ]);

        $payload = $this->validIpnPayload([
            'message_type'  => 'CHARGEBACK_REPORTED',
            'invoice_id'    => 'INV-CB-PLAN-MATCH',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan); // downgraded

        $transaction->refresh();
        $this->assertEquals('chargeback', $transaction->status);
    }

    // ── P1-1: afterCommit — side effects don't run on rollback ───────────

    public function test_upgrade_email_sent_only_after_commit(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\PlanUpgradedEmail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id && $mail->plan === 'pro';
        });
    }

    // ── Demo-mode notifications never mutate state ───────────────────────

    public function test_demo_order_created_does_not_upgrade(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
            'demo'      => 'Y',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_non_demo_order_created_upgrades(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
            'demo'      => 'N',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    // ── Client-tamperable reference fields cannot assign subscriptions ──

    public function test_numeric_external_reference_cannot_bind_arbitrary_user(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1'          => self::PRODUCT_ID_PRO,
            'external-reference' => (string) $victim->id,
            'customer_email'     => 'attacker-unrelated@example.com',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $victim->refresh();
        $this->assertEquals('free', $victim->plan);
        $this->assertDatabaseMissing('transactions', ['user_id' => $victim->id]);
    }

    public function test_merchant_item_id_cannot_bind_arbitrary_user(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1'          => self::PRODUCT_ID_PRO,
            'merchant_item_id_1' => (string) $victim->id,
            'customer_email'     => 'attacker-unrelated@example.com',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $victim->refresh();
        $this->assertEquals('free', $victim->plan);
        $this->assertDatabaseMissing('transactions', ['user_id' => $victim->id]);
    }

    // ── A confirmed purchase supersedes a previous live subscription ─────

    public function test_one_time_purchase_cancels_replaced_subscription_at_2co(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.2checkout.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $user = User::factory()->pro()->create([
            'email'               => 'buyer@example.com',
            'subscription_id'     => 'OLD-SUB-1',
            'subscription_status' => 'active',
            'subscription_ends_at'=> now()->addDays(20),
        ]);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_STUDIO, // one-time studio purchase
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan);
        $this->assertEquals('cancelled', $user->subscription_status);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/6.0/subscriptions/OLD-SUB-1/cancel');
        });
    }

    public function test_recurring_purchase_replaces_and_cancels_previous_subscription(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.2checkout.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $user = User::factory()->pro()->create([
            'email'               => 'buyer@example.com',
            'subscription_id'     => 'OLD-SUB-2',
            'subscription_status' => 'active',
            'subscription_ends_at'=> now()->addDays(20),
        ]);

        $payload = $this->validIpnPayload([
            'item_id_1'           => self::PRODUCT_ID_STUDIO,
            'recurring_order_id'  => 'NEW-SUB-2',
            'item_billing_cycle_next_date' => now()->addMonth()->toDateString(),
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('studio', $user->plan);
        $this->assertEquals('NEW-SUB-2', $user->subscription_id);
        $this->assertEquals('active', $user->subscription_status);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/6.0/subscriptions/OLD-SUB-2/cancel');
        });
    }

    public function test_order_created_without_prior_subscription_does_not_call_cancel_api(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.2checkout.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $payload = $this->validIpnPayload([
            'item_id_1' => self::PRODUCT_ID_PRO,
        ]);

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('pro', $user->plan);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}

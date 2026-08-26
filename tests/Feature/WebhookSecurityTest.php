<?php

declare(strict_types=1);

/**
 * Iteration-002 regression tests for audit 2CO-3 (webhook env gate),
 * 2CO-4 (replay protection race), and 2CO-5 (refund amount logging).
 *
 * Run: php artisan test --filter=WebhookSecurityTest
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_2co3_md5_only_webhook_rejected_in_staging_env(): void
    {
        // 2CO-3 FIX: the old gate was APP_ENV === 'production', so staging
        // (APP_ENV=staging) accepted MD5-only. The new gate uses an allowlist
        // ['local', 'testing'] — staging should now fail closed.
        $this->app['env'] = 'staging';
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null); // HMAC not configured
        config()->set('services.2checkout.allow_md5_only', false);

        // Build a valid MD5 hash for the request
        $saleId = 'SALE123';
        $vendorId = 'VENDOR123';
        $invoiceId = 'INV123';
        $secretWord = 'TESTSECRET';
        $stringToHash = strlen($saleId) . $saleId . strlen($vendorId) . $vendorId . strlen($invoiceId) . $invoiceId . strlen($secretWord) . $secretWord;
        $md5Hash = strtoupper(md5($stringToHash));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/webhooks/2checkout', [
                'message_type' => 'ORDER_CREATED',
                'message_id'   => 'msg-' . uniqid(),
                'sale_id'      => $saleId,
                'vendor_id'    => $vendorId,
                'invoice_id'   => $invoiceId,
                'md5_hash'     => $md5Hash,
                // No 'signature' field → MD5-only mode
            ]);

        // 2CO-3 FIX: staging env should reject (403), not accept (200)
        $response->assertStatus(403);
    }

    public function test_2co3_md5_only_webhook_accepted_in_local_env(): void
    {
        // 2CO-3 FIX: 'local' and 'testing' are in the allowlist — MD5-only is accepted
        $this->app['env'] = 'local';
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);
        config()->set('services.2checkout.allow_md5_only', false);

        $saleId = 'SALE456';
        $vendorId = 'VENDOR456';
        $invoiceId = 'INV456';
        $secretWord = 'TESTSECRET';
        $stringToHash = strlen($saleId) . $saleId . strlen($vendorId) . $vendorId . strlen($invoiceId) . $invoiceId . strlen($secretWord) . $secretWord;
        $md5Hash = strtoupper(md5($stringToHash));

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type' => 'ORDER_CREATED',
            'message_id'   => 'msg-local-' . uniqid(),
            'sale_id'      => $saleId,
            'vendor_id'    => $vendorId,
            'invoice_id'   => $invoiceId,
            'md5_hash'     => $md5Hash,
        ]);

        // local env: MD5-only accepted (returns 200, not 403)
        $response->assertStatus(200);
    }

    public function test_2co4_replay_protection_handles_duplicate_concurrent_inserts(): void
    {
        // 2CO-4 FIX: the old exists() + insert() pattern had a race window.
        // The new insertOrIgnore() is atomic — duplicate inserts return 0
        // (no exception, no 500). This test verifies the behavior by
        // simulating a duplicate message_id.
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);
        $this->app['env'] = 'testing';

        $saleId = 'SALE-2CO4';
        $vendorId = 'VENDOR-2CO4';
        $invoiceId = 'INV-2CO4';
        $secretWord = 'TESTSECRET';
        $stringToHash = strlen($saleId) . $saleId . strlen($vendorId) . $vendorId . strlen($invoiceId) . $invoiceId . strlen($secretWord) . $secretWord;
        $md5Hash = strtoupper(md5($stringToHash));

        $messageId = 'msg-duplicate-' . uniqid();

        $payload = [
            'message_type' => 'ORDER_CREATED',
            'message_id'   => $messageId,
            'sale_id'      => $saleId,
            'vendor_id'    => $vendorId,
            'invoice_id'   => $invoiceId,
            'md5_hash'     => $md5Hash,
        ];

        // First request — should be accepted (200)
        $response1 = $this->postJson('/webhooks/2checkout', $payload);
        $response1->assertStatus(200);

        // Second request with the SAME message_id — should be a no-op (200, not 500)
        // 2CO-4 FIX: previously this would throw a duplicate-key exception → 500.
        $response2 = $this->postJson('/webhooks/2checkout', $payload);
        $response2->assertStatus(200);

        // Verify only one row in processed_webhooks
        $count = DB::table('processed_webhooks')
            ->where('message_id', $messageId)
            ->count();
        $this->assertEquals(1, $count, '2CO-4: Duplicate message_id should result in exactly 1 row in processed_webhooks.');
    }

    public function test_2co5_refund_amount_logging_emits_info_log(): void
    {
        // 2CO-5 FIX: the refund amount assumption (item_list_amount_1 is the
        // refund amount, not the original) is now logged at INFO level for
        // verification. This test verifies the log is emitted.
        Log::spy();

        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);
        $this->app['env'] = 'testing';

        // Create a transaction to be refunded
        // ITERATION-1 FIX: transactions.user_id is FK-constrained on SQLite
        // — user id 1 never existed in this test.
        $refundUser = User::factory()->create();
        $transaction = DB::table('transactions')->insertGetId([
            'user_id'        => $refundUser->id,
            'invoice_id'     => 'INV-2CO5-TEST',
            'sale_id'        => 'SALE-2CO5',
            'product_id'     => 'PRO-PRODUCT',
            'plan'           => 'pro',
            'amount'         => 29.00,
            'currency'       => 'USD',
            'customer_email' => 'test@example.com',
            'customer_name'  => 'Test User',
            'status'         => 'completed',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $saleId = 'SALE-2CO5';
        $vendorId = 'VENDOR-2CO5';
        $invoiceId = 'INV-2CO5-TEST';
        $secretWord = 'TESTSECRET';
        $stringToHash = strlen($saleId) . $saleId . strlen($vendorId) . $vendorId . strlen($invoiceId) . $invoiceId . strlen($secretWord) . $secretWord;
        $md5Hash = strtoupper(md5($stringToHash));

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type'      => 'REFUND_ISSUED',
            'message_id'        => 'msg-refund-' . uniqid(),
            'sale_id'           => $saleId,
            'vendor_id'         => $vendorId,
            'invoice_id'        => $invoiceId,
            'md5_hash'          => $md5Hash,
            'item_list_amount_1' => 29.00, // full refund
        ]);

        $response->assertStatus(200);

        // 2CO-5 FIX: verify the INFO log was emitted with the refund analysis
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                return $message === '2Checkout: REFUND_ISSUED amount analysis'
                    && isset($context['raw_refund_field'])
                    && isset($context['original_amount'])
                    && isset($context['is_full_refund']);
            })
            ->atLeast()
            ->once();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clean up any leftover processed_webhooks rows from previous tests
        DB::table('processed_webhooks')->delete();
    }
}

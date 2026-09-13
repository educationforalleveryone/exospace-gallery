<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_md5_webhook_accepted_in_staging_env(): void
    {
        $this->app['env'] = 'staging';
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/webhooks/2checkout', $this->signedPayload('staging-'));

        // md5_hash is the documented 2Checkout INS mechanism — valid hashes
        // are accepted in every environment.
        $response->assertStatus(200);
    }

    public function test_invalid_md5_webhook_rejected_in_staging_env(): void
    {
        $this->app['env'] = 'staging';
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);

        $payload = $this->signedPayload('staging-invalid-');
        $payload['md5_hash'] = str_repeat('A', 32);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/webhooks/2checkout', $payload);

        $response->assertStatus(403);
    }

    public function test_valid_md5_webhook_accepted_in_local_env(): void
    {
        $this->app['env'] = 'local';
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);

        $response = $this->postJson('/webhooks/2checkout', $this->signedPayload('local-'));

        $response->assertStatus(200);
    }

    public function test_2co4_replay_protection_handles_duplicate_concurrent_inserts(): void
    {
        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);
        $this->app['env'] = 'testing';

        $messageId = 'msg-duplicate-' . uniqid();
        $payload = $this->signedPayload('dup-', $messageId);

        $response1 = $this->postJson('/webhooks/2checkout', $payload);
        $response1->assertStatus(200);

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
        Log::spy();

        config()->set('services.2checkout.secret_word', 'TESTSECRET');
        config()->set('services.2checkout.buy_link_secret_word', null);
        $this->app['env'] = 'testing';

        $refundUser = User::factory()->create();
        DB::table('transactions')->insertGetId([
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

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type'      => 'REFUND_ISSUED',
            'message_id'        => 'msg-refund-' . uniqid(),
            'sale_id'           => 'SALE-2CO5',
            'vendor_id'         => 'VENDOR-2CO5',
            'invoice_id'        => 'INV-2CO5-TEST',
            'md5_hash'          => $this->md5HashFor('SALE-2CO5', 'VENDOR-2CO5', 'INV-2CO5-TEST'),
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

    private function md5HashFor(string $saleId, string $vendorId, string $invoiceId, string $secretWord = 'TESTSECRET'): string
    {
        return strtoupper(md5(
            strtoupper(md5($saleId)) . $vendorId . $invoiceId . $secretWord
        ));
    }

    private function signedPayload(string $prefix, ?string $messageId = null): array
    {
        $saleId = 'SALE-' . uniqid($prefix);
        $vendorId = 'VENDOR-' . $prefix;
        $invoiceId = 'INV-' . uniqid($prefix);

        return [
            'message_type' => 'ORDER_CREATED',
            'message_id'   => $messageId ?? ('msg-' . uniqid($prefix)),
            'sale_id'      => $saleId,
            'vendor_id'    => $vendorId,
            'invoice_id'   => $invoiceId,
            'md5_hash'     => $this->md5HashFor($saleId, $vendorId, $invoiceId),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clean up any leftover processed_webhooks rows from previous tests
        DB::table('processed_webhooks')->delete();
    }
}

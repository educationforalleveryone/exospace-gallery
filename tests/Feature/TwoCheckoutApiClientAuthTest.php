<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TwoCheckoutApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwoCheckoutApiClientAuthTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = 'TESTMERCHANT';

    private const SECRET = 'test-secret-word';

    private function client(): TwoCheckoutApiClient
    {
        return new TwoCheckoutApiClient(self::MERCHANT, self::SECRET);
    }

    public function test_auth_hash_covers_exact_transmitted_body_bytes(): void
    {
        Http::fake([
            'api.2checkout.com/*' => Http::response(['success' => true], 200),
        ]);

        $reason = 'Payment dispute 12/06 — refunded per policy/contract';

        $this->client()->cancelSubscription('sub-777', $reason);

        $expectedBody = json_encode([
            'merchant_code' => self::MERCHANT,
            'reason' => $reason,
        ], JSON_UNESCAPED_SLASHES);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($expectedBody) {
            if ($request->url() !== 'https://api.2checkout.com/rest/6.0/subscriptions/sub-777/cancel') {
                return false;
            }

            // The authentication header must be derivable from the bytes that
            // actually went over the wire — otherwise the provider rejects the call.
            $expectedAuth = base64_encode(
                self::MERCHANT.':'.hash('sha1', $request->body().self::SECRET)
            );

            return $request->body() === $expectedBody
                && $request->header('X-Avangate-Authentication')[0] === $expectedAuth
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_get_request_hashes_empty_payload_and_sends_no_body(): void
    {
        Http::fake([
            'api.2checkout.com/*' => Http::response(['SubscriptionEnabled' => true], 200),
        ]);

        $this->client()->getSubscription('sub-888');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== 'https://api.2checkout.com/rest/6.0/subscriptions/sub-888') {
                return false;
            }

            $expectedAuth = base64_encode(
                self::MERCHANT.':'.hash('sha1', ''.self::SECRET)
            );

            return $request->body() === ''
                && $request->header('X-Avangate-Authentication')[0] === $expectedAuth;
        });
    }

    public function test_refund_request_body_matches_signed_payload(): void
    {
        Http::fake([
            'api.2checkout.com/*' => Http::response(['success' => true], 200),
        ]);

        $this->client()->issueRefund('INV-42', 29.0, 'Customer request');

        $expectedBody = json_encode([
            'merchant_code' => self::MERCHANT,
            'amount' => 29.0,
            'reason' => 'Customer request',
        ], JSON_UNESCAPED_SLASHES);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($expectedBody) {
            if ($request->url() !== 'https://api.2checkout.com/rest/6.0/orders/INV-42/refund') {
                return false;
            }

            $expectedAuth = base64_encode(
                self::MERCHANT.':'.hash('sha1', $request->body().self::SECRET)
            );

            return $request->body() === $expectedBody
                && $request->header('X-Avangate-Authentication')[0] === $expectedAuth;
        });
    }
}

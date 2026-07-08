<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Iteration-002 (audit 2CO-1): TwoCheckout (Verifone) REST API client.
 *
 * CRITICAL FIX: The previous BillingController used a "simplified placeholder"
 * authentication scheme that 2Checkout's API rejects. Every cancelSubscription
 * and reactivateSubscription call returned 401/403, leaving paying customers
 * unable to self-serve cancel — they had to email support. The code itself
 * contained a comment admitting: "This is a simplified placeholder; the founder
 * must configure the correct auth per their 2Checkout account's API settings."
 *
 * This client implements 2Checkout's documented authentication:
 *   - X-Avangate-Authentication header
 *   - Format: base64(merchant_code:sha1(payload_json + secret_word))
 *   - The header is sent on every request. For GET requests (no body), the
 *     payload is an empty string.
 *
 * Reference: https://verifone.cloud/docs/2checkout/api-integration
 *
 * Usage:
 *   $client = app(TwoCheckoutApiClient::class);
 *   $response = $client->cancelSubscription($subscriptionId);
 *   if (! $response->successful()) { Log::error(...); }
 *
 * Configuration (in config/services.php under '2checkout'):
 *   - account_number: merchant code (TWOCHECKOUT_ACCOUNT_NUMBER env)
 *   - secret_word: the SAME secret word used for webhook MD5 verification
 *     (TWOCHECKOUT_SECRET_WORD env). Note: 2Checkout has separate "secret
 *     word" and "buy-link secret word" — the REST API uses the former.
 *   - sandbox: if true, uses the sandbox API base URL (TWOCHECKOUT_SANDBOX env,
 *     default false).
 *
 * All methods return the raw HTTP Response. Callers should check
 * $response->successful() and log $response->status() + $response->body()
 * on failure.
 */
class TwoCheckoutApiClient
{
    private const SANDBOX_BASE_URL = 'https://api-sandbox.2checkout.com';
    private const PRODUCTION_BASE_URL = 'https://api.2checkout.com';

    public function __construct(
        private readonly string $merchantCode,
        private readonly string $secretWord,
        private readonly bool $sandbox = false,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Cancel an active subscription.
     *
     * @param  string  $subscriptionId  The 2Checkout subscription ID (stored on user.subscription_id)
     * @param  string  $reason  Optional cancellation reason (logged for audit)
     * @return Response
     */
    public function cancelSubscription(string $subscriptionId, string $reason = 'Customer requested cancellation via self-serve billing portal'): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}/cancel";
        $payload = [
            'merchant_code' => $this->merchantCode,
            'reason' => $reason,
        ];

        return $this->send('POST', $endpoint, $payload);
    }

    /**
     * Reactivate a cancelled subscription (only works if still within the paid-for period).
     *
     * @param  string  $subscriptionId
     * @return Response
     */
    public function reactivateSubscription(string $subscriptionId): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}/reactivate";
        $payload = [
            'merchant_code' => $this->merchantCode,
        ];

        return $this->send('POST', $endpoint, $payload);
    }

    /**
     * Get subscription details (status, next billing date, etc.).
     *
     * @param  string  $subscriptionId
     * @return Response
     */
    public function getSubscription(string $subscriptionId): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}";
        return $this->send('GET', $endpoint);
    }

    /**
     * Issue a refund for a specific invoice.
     *
     * @param  string  $invoiceId
     * @param  float  $amount
     * @param  string  $reason
     * @return Response
     */
    public function issueRefund(string $invoiceId, float $amount, string $reason = 'Customer request'): Response
    {
        $endpoint = "/rest/6.0/orders/{$invoiceId}/refund";
        $payload = [
            'merchant_code' => $this->merchantCode,
            'amount' => $amount,
            'reason' => $reason,
        ];

        return $this->send('POST', $endpoint, $payload);
    }

    /**
     * Send an authenticated request to the 2Checkout API.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, DELETE)
     * @param  string  $endpoint  API path (starts with /)
     * @param  array  $payload  Request body (for POST/PUT)
     * @return Response
     */
    private function send(string $method, string $endpoint, array $payload = []): Response
    {
        $baseUrl = $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
        $url = rtrim($baseUrl, '/') . $endpoint;

        // Build the auth header per 2Checkout's documented format.
        // For GET requests with no body, the payload is an empty string.
        $payloadJson = empty($payload) ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);

        $authHash = hash('sha1', $payloadJson . $this->secretWord);
        $authHeader = base64_encode($this->merchantCode . ':' . $authHash);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Avangate-Authentication' => $authHeader,
        ];

        // Log the request (without the secret)
        Log::info('2Checkout API request', [
            'method' => $method,
            'url' => $url,
            'merchant_code' => $this->merchantCode,
            'has_payload' => ! empty($payload),
        ]);

        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->{strtolower($method)}($url, empty($payload) ? null : $payload);

        if (! $response->successful()) {
            Log::error('2Checkout API request failed', [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response;
    }

    /**
     * Verify that the client is configured correctly.
     * Called by the PreflightCheck command to fail-fast on missing config.
     */
    public static function isConfigured(): bool
    {
        $merchantCode = config('services.2checkout.account_number');
        $secretWord = config('services.2checkout.secret_word');

        return ! empty($merchantCode) && ! empty($secretWord);
    }
}

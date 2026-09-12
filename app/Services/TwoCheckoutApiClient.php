<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function cancelSubscription(string $subscriptionId, string $reason = 'Customer requested cancellation via self-serve billing portal'): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}/cancel";
        $payload = [
            'merchant_code' => $this->merchantCode,
            'reason' => $reason,
        ];

        return $this->send('POST', $endpoint, $payload);
    }

    public function reactivateSubscription(string $subscriptionId): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}/reactivate";
        $payload = [
            'merchant_code' => $this->merchantCode,
        ];

        return $this->send('POST', $endpoint, $payload);
    }

    public function getSubscription(string $subscriptionId): Response
    {
        $endpoint = "/rest/6.0/subscriptions/{$subscriptionId}";
        return $this->send('GET', $endpoint);
    }

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

    private function send(string $method, string $endpoint, array $payload = []): Response
    {
        $baseUrl = $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
        $url = rtrim($baseUrl, '/') . $endpoint;

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

    public static function isConfigured(): bool
    {
        $merchantCode = config('services.2checkout.account_number');
        $secretWord = config('services.2checkout.secret_word');

        return ! empty($merchantCode) && ! empty($secretWord);
    }
}

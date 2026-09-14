<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct() {}

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        $secretKey = config('services.turnstile.secret_key');

        if (! $secretKey) {
            return true;
        }

        if (! $token) {
            // Token is missing but Turnstile IS configured → reject.
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, [
                    'secret'   => $secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);

            $body = $response->json();

            if (! is_array($body) || ! isset($body['success'])) {
                Log::warning('TurnstileService: unexpected response shape', [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);
                return false;
            }

            if (! $body['success']) {
                Log::info('TurnstileService: verification failed', [
                    'errors'   => $body['error-codes'] ?? [],
                    'remoteip' => $remoteIp,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('TurnstileService: siteverify call failed (fail-open)', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.turnstile.site_key');
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoolifyDomainManager
{
    private ?string $token;
    private ?string $baseUrl;
    private ?string $appUuid;

    public function __construct()
    {
        $this->token    = config('services.coolify.api_token');
        $this->baseUrl  = (string) config('services.coolify.api_base_url', '');
        $this->appUuid  = config('services.coolify.application_uuid');
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->baseUrl) && !empty($this->appUuid);
    }

    public function addDomain(string $domain): array
    {
        $domain = $this->normalize($domain);
        if (!$domain) {
            return ['success' => false, 'message' => 'Invalid domain.'];
        }

        if (!$this->isConfigured()) {
            Log::warning('CoolifyDomainManager: not configured — skipping addDomain.', [
                'domain' => $domain,
            ]);
            return [
                'success' => false,
                'message' => 'Coolify API not configured. Add COOLIFY_API_TOKEN, COOLIFY_API_BASE_URL, and COOLIFY_APPLICATION_UUID to your .env, or add the domain manually in Coolify.',
            ];
        }

        $lock = Cache::lock($this->lockKey(), 30);

        try {
            return $lock->block(10, function () use ($domain) {
                $current = $this->getCurrentDomains();

                // Already there?
                if (is_array($current) && in_array($domain, $current, true)) {
                    return ['success' => true, 'message' => "Domain '{$domain}' is already in Coolify's domain list."];
                }

                if ($current === null) {
                    return ['success' => false, 'message' => 'Could not fetch current domains from Coolify API. Check the logs.'];
                }

                $newList = array_merge($current, [$domain]);
                $result = $this->updateDomains($newList);

                if ($result) {
                    // Bust the cache so subsequent reads see the new list
                    Cache::forget($this->cacheKey());
                    Log::info('CoolifyDomainManager: added domain.', ['domain' => $domain]);
                    return [
                        'success' => true,
                        'message' => "Domain '{$domain}' added to Coolify. SSL cert will be provisioned automatically (may take 1-5 minutes).",
                    ];
                }

                return ['success' => false, 'message' => 'Coolify API call failed. Check the logs.'];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('CoolifyDomainManager: addDomain lock busy, another worker is updating Coolify domains', [
                'domain' => $domain,
            ]);
            return [
                'success' => false,
                'message' => 'Another domain update is in progress. Please retry in a moment.',
            ];
        } catch (\Throwable $e) {
            Log::error('CoolifyDomainManager: addDomain failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unexpected error. Check the logs.'];
        }
    }

    public function removeDomain(string $domain): array
    {
        $domain = $this->normalize($domain);
        if (!$domain) {
            return ['success' => false, 'message' => 'Invalid domain.'];
        }

        if (!$this->isConfigured()) {
            // If we never could have added it, removing is a no-op success
            return ['success' => true, 'message' => 'Coolify API not configured — nothing to remove.'];
        }

        $lock = Cache::lock($this->lockKey(), 30);

        try {
            return $lock->block(10, function () use ($domain) {
                $current = $this->getCurrentDomains();

                if ($current === null) {
                    return ['success' => false, 'message' => 'Could not fetch current domains from Coolify API.'];
                }

                if (!in_array($domain, $current, true)) {
                    return ['success' => true, 'message' => "Domain '{$domain}' not in Coolify's domain list — nothing to remove."];
                }

                $newList = array_values(array_diff($current, [$domain]));
                $result = $this->updateDomains($newList);

                if ($result) {
                    Cache::forget($this->cacheKey());
                    Log::info('CoolifyDomainManager: removed domain.', ['domain' => $domain]);
                    return [
                        'success' => true,
                        'message' => "Domain '{$domain}' removed from Coolify.",
                    ];
                }

                return ['success' => false, 'message' => 'Coolify API call failed. Check the logs.'];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('CoolifyDomainManager: removeDomain lock busy', ['domain' => $domain]);
            return [
                'success' => false,
                'message' => 'Another domain update is in progress. Please retry in a moment.',
            ];
        } catch (\Throwable $e) {
            Log::error('CoolifyDomainManager: removeDomain failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unexpected error. Check the logs.'];
        }
    }

    public function getCurrentDomains(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return Cache::remember($this->cacheKey(), now()->addMinutes(5), function () {
            try {
                $resp = Http::withToken($this->token)
                    ->timeout(10)
                    ->get("{$this->baseUrl}/api/v1/applications/{$this->appUuid}");

                if (!$resp->successful()) {
                    Log::error('CoolifyDomainManager: GET application failed.', [
                        'status' => $resp->status(),
                        'body'   => $resp->body(),
                    ]);
                    // Throw so Cache::remember does NOT cache null.
                    throw new \RuntimeException("Coolify API GET failed: HTTP {$resp->status()}");
                }

                $data = $resp->json();
                $domains = $data['domains'] ?? '';

                // Coolify stores domains as a comma-separated string
                $list = array_filter(array_map('trim', explode(',', $domains)));
                return array_values($list);
            } catch (ConnectionException $e) {
                Log::error('CoolifyDomainManager: connection error.', ['message' => $e->getMessage()]);
                // Throw so Cache::remember does NOT cache null.
                throw $e;
            } catch (\Throwable $e) {
                Log::error('CoolifyDomainManager: unexpected error.', ['message' => $e->getMessage()]);
                // Throw so Cache::remember does NOT cache null.
                throw $e;
            }
        });
    }

    private function updateDomains(array $domains): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $resp = Http::withToken($this->token)
                ->timeout(15)
                ->patch("{$this->baseUrl}/api/v1/applications/{$this->appUuid}", [
                    'domains' => implode(',', $domains),
                ]);

            if (!$resp->successful()) {
                Log::error('CoolifyDomainManager: PATCH application failed.', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('CoolifyDomainManager: connection error on PATCH.', ['message' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            Log::error('CoolifyDomainManager: unexpected error on PATCH.', ['message' => $e->getMessage()]);
            return false;
        }
    }

    private function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }

    private function cacheKey(): string
    {
        return "coolify:domains:{$this->appUuid}";
    }

    private function lockKey(): string
    {
        return "coolify:domains:lock:{$this->appUuid}";
    }
}

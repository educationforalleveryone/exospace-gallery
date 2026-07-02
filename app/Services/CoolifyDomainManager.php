<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages custom domains in Coolify via its REST API.
 *
 * WHY THIS EXISTS
 * --------------
 * When a Studio-plan user sets `custom_domain` on their gallery, three
 * things must happen for the domain to actually serve HTTPS traffic:
 *
 *   1. DNS: the user CNAMES their domain to exospace.gallery.
 *   2. Coolify must know about the domain so Traefik (Coolify's
 *      reverse proxy) routes it to this app's container.
 *   3. Let's Encrypt must issue a cert for the domain — Coolify
 *      triggers this when the domain is added via the UI or API.
 *
 * Step 2 + 3 happen automatically via this service. Without it, you'd
 * have to manually click "Add domain" in the Coolify UI for every
 * custom domain a user configures — not scalable for a SaaS.
 *
 * COOLIFY API DOCS
 * ----------------
 * Coolify's API is documented at: https://coolify.io/docs/api-reference
 * The endpoints this service uses:
 *
 *   GET    /api/v1/applications/{uuid}
 *          → fetch the application, including its current domains
 *
 *   PATCH  /api/v1/applications/{uuid}
 *          → update the application, including setting `domains`
 *
 * The `domains` field is a comma-separated string of all domains that
 * should route to this app. We append the new custom domain to the list.
 *
 * ENVIRONMENT VARIABLES
 * ---------------------
 *   COOLIFY_API_TOKEN         Personal access token from Coolify user settings
 *   COOLIFY_API_BASE_URL      e.g. https://coolify.yourdomain.com
 *   COOLIFY_APPLICATION_UUID  The UUID of the Exospace application in Coolify
 *
 * All three must be set. If any is missing, the service no-ops gracefully
 * (logs a warning, returns false) so the app doesn't crash — but the
 * custom domain won't work until you either set the env vars OR add the
 * domain manually via the Coolify UI.
 *
 * CACHING (task C13)
 * -------
 * The current `domains` list is cached for 5 minutes per app UUID so we
 * don't hammer the Coolify API. After a successful update, the cache is
 * busted.
 *
 * FAILURE MODES FIXED IN TASK C13:
 *   1. Cache-nulls bug: previously, a transient network blip cached `null`
 *      for 5 minutes, locking out ALL custom-domain provisioning. Now
 *      `getCurrentDomains()` throws on failure instead of caching null —
 *      the caller can retry, and the cache stays warm with the last known
 *      good value (or empty if never fetched).
 *
 *   2. Read-modify-write race: previously, two concurrent `addDomain()`
 *      calls both read the same cached list, both merge their own domain,
 *      both PATCH — last PATCH wins, the first user's domain silently
 *      disappears from Coolify. Now `addDomain()` and `removeDomain()`
 *      acquire a per-app cache lock for the duration of the read-modify-
 *      write, so concurrent calls serialize.
 *
 * If you ever change domains manually in the Coolify UI, run:
 *
 *     php artisan cache:clear
 *
 * to force the next save to re-fetch the live list.
 */
class CoolifyDomainManager
{
    private ?string $token;
    private ?string $baseUrl;
    private ?string $appUuid;

    public function __construct()
    {
        // Read from config() rather than env() directly. This makes the
        // service safe under `php artisan config:cache` — env() returns
        // null outside of config files once the config is cached, which
        // would silently break custom-domain provisioning. (Task C14.)
        $this->token    = config('services.coolify.api_token');
        $this->baseUrl  = (string) config('services.coolify.api_base_url', '');
        $this->appUuid  = config('services.coolify.application_uuid');
    }

    /**
     * Is the Coolify integration configured?
     * Returns false if any of the three env vars is missing.
     */
    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->baseUrl) && !empty($this->appUuid);
    }

    /**
     * Add a custom domain to the Coolify application.
     *
     * (Task C13) Wraps the read-modify-write in a per-app cache lock so
     * two concurrent calls serialize. Previously, a race could cause one
     * caller's domain to be silently dropped from the PATCH.
     *
     * @param  string  $domain  The domain to add (e.g. "gallery.janedoe.com")
     * @return array{success: bool, message: string}
     */
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

        // Per-app lock — serializes concurrent addDomain/removeDomain calls
        // so the read-modify-write is atomic. 30-second TTL, 10-second block
        // timeout (the caller waits up to 10s for another caller to finish).
        $lock = Cache::lock($this->lockKey(), 30);

        try {
            return $lock->block(10, function () use ($domain) {
                $current = $this->getCurrentDomains();

                // Already there?
                if (is_array($current) && in_array($domain, $current, true)) {
                    return ['success' => true, 'message' => "Domain '{$domain}' is already in Coolify's domain list."];
                }

                // getCurrentDomains() returns null on failure — but unlike the
                // pre-C13 behavior, it does NOT cache the null. We treat a null
                // return as "fetch failed, tell the caller to retry".
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

    /**
     * Remove a custom domain from the Coolify application.
     * Called when a user clears their custom_domain field or deletes the gallery.
     *
     * (Task C13) Same per-app lock as addDomain() to serialize the
     * read-modify-write.
     */
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

    /**
     * Get the current list of domains Coolify routes to this app.
     *
     * (Task C13) CRITICAL: does NOT cache null on failure. If the HTTP
     * call fails, we throw — `Cache::remember` only caches the return
     * value of a successful closure. The caller can retry immediately,
     * and a transient network blip no longer locks out all provisioning
     * for 5 minutes.
     *
     * @return string[]|null  Array of domains, or null on failure.
     */
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

    /**
     * PATCH the application's `domains` field with the new list.
     */
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

    /**
     * Per-application lock key for serializing addDomain/removeDomain calls.
     * (Task C13) — prevents the read-modify-write race where two concurrent
     * callers both PATCH and the second one's list (without the first one's
     * domain) overwrites the first.
     */
    private function lockKey(): string
    {
        return "coolify:domains:lock:{$this->appUuid}";
    }
}

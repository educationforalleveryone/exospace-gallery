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
 * CACHING
 * -------
 * The current `domains` list is cached for 5 minutes per app UUID so we
 * don't hammer the Coolify API. After a successful update, the cache is
 * busted. If you ever change domains manually in the Coolify UI, run:
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
        $this->token = env('COOLIFY_API_TOKEN');
        $this->baseUrl = rtrim((string) env('COOLIFY_API_BASE_URL', ''), '/');
        $this->appUuid = env('COOLIFY_APPLICATION_UUID');
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

        $current = $this->getCurrentDomains();
        if ($current === null) {
            return ['success' => false, 'message' => 'Could not fetch current domains from Coolify API. Check the logs.'];
        }

        // Already there?
        if (in_array($domain, $current, true)) {
            return ['success' => true, 'message' => "Domain '{$domain}' is already in Coolify's domain list."];
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
    }

    /**
     * Remove a custom domain from the Coolify application.
     * Called when a user clears their custom_domain field or deletes the gallery.
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
    }

    /**
     * Get the current list of domains Coolify routes to this app.
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
                    return null;
                }

                $data = $resp->json();
                $domains = $data['domains'] ?? '';

                // Coolify stores domains as a comma-separated string
                $list = array_filter(array_map('trim', explode(',', $domains)));
                return array_values($list);
            } catch (ConnectionException $e) {
                Log::error('CoolifyDomainManager: connection error.', ['message' => $e->getMessage()]);
                return null;
            } catch (\Throwable $e) {
                Log::error('CoolifyDomainManager: unexpected error.', ['message' => $e->getMessage()]);
                return null;
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
}

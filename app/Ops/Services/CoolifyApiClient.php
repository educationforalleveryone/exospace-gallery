<?php

declare(strict_types=1);

namespace App\Ops\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — CoolifyApiClient.
 *
 * A thin, READ-ONLY client over the Coolify REST API. Shares the SAME
 * credentials CoolifyDomainManager already uses (config/services.php →
 * 'coolify' — COOLIFY_API_TOKEN / COOLIFY_API_BASE_URL): no new secrets,
 * no new env vars.
 *
 * Why not extend CoolifyDomainManager: that class is a focused,
 * battle-tested write-path for domains with its own locking/caching
 * semantics (tasks C13/C14). This client is the read-path the control
 * plane needs. Both share config; neither knows about the other.
 *
 * Degradation contract (important — Coolify API surface varies by
 * version): every method returns null on ANY failure (404, 401, timeout,
 * malformed JSON) and logs the reason at debug/warning level. Sync logic
 * treats null as "this endpoint isn't available on this Coolify version"
 * and moves on. NOTHING here throws.
 *
 * Coolify API docs: https://coolify.io/docs/api-reference
 */
class CoolifyApiClient
{
    private ?string $token;

    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->token = config('services.coolify.api_token');
        $this->baseUrl = rtrim((string) config('services.coolify.api_base_url', ''), '/');
        $this->timeout = (int) config('ops.platform_sync.timeout', 15);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token) && $this->baseUrl !== '';
    }

    /**
     * Raw GET returning the decoded JSON body, or null on any failure.
     *
     * @return array<int|string,mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->baseUrl.$path, $query);

            if ($response->status() === 404) {
                // Endpoint not present on this Coolify version — expected
                // for some fields; debug-level only to avoid alert noise.
                Log::debug('CoolifyApiClient: endpoint not found', ['path' => $path]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('CoolifyApiClient: request failed', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (ConnectionException $e) {
            Log::warning('CoolifyApiClient: connection failed', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('CoolifyApiClient: unexpected failure', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Is the API reachable at all right now? (Cached briefly by the caller
     * — see PlatformSyncService.)
     */
    public function reachable(): bool
    {
        $teams = $this->get('/api/v1/teams');

        return $teams !== null;
    }

    // ── Resource listings ─────────────────────────────────────────────

    /**
     * All servers known to Coolify.
     *
     * @return array<int, array<string, mixed>>
     */
    public function servers(): array
    {
        return $this->listOf($this->get('/api/v1/servers'));
    }

    /**
     * All applications deployed through Coolify (every project on the box).
     *
     * @return array<int, array<string, mixed>>
     */
    public function applications(): array
    {
        return $this->listOf($this->get('/api/v1/applications'));
    }

    /**
     * Managed databases (MySQL, Redis, Postgres, ...).
     *
     * @return array<int, array<string, mixed>>
     */
    public function databases(): array
    {
        return $this->listOf($this->get('/api/v1/databases'));
    }

    /**
     * Composite services.
     *
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        return $this->listOf($this->get('/api/v1/services'));
    }

    /**
     * Recent deployments for one application. Endpoint availability varies
     * by Coolify version — returns [] when unsupported.
     *
     * @return array<int, array<string, mixed>>
     */
    public function applicationDeployments(string $applicationUuid): array
    {
        return $this->listOf($this->get("/api/v1/applications/{$applicationUuid}/deployments"));
    }

    /**
     * A single deployment by UUID (fallback for versions without the list
     * endpoint).
     *
     * @return array<string, mixed>|null
     */
    public function deployment(string $deploymentUuid): ?array
    {
        $data = $this->get("/api/v1/deployments/{$deploymentUuid}");

        return $data;
    }

    /**
     * A single application by UUID (fresh status for container.health).
     *
     * @return array<string, mixed>|null
     */
    public function applicationByUuid(string $applicationUuid): ?array
    {
        return $this->get("/api/v1/applications/{$applicationUuid}");
    }

    /**
     * ITERATION 3 — the client's ONLY write operation.
     *
     * Restart an application container: POST /applications/{uuid}/restart.
     * Coolify stops the current container and starts a new one from the SAME
     * image (no rebuild, no code change — that is what makes this a "safe
     * remediation" rather than a deployment action; deploys remain Coolify's
     * job). Returns the response body on success (Coolify versions return
     * e.g. a deployment uuid) or null on ANY failure — the same
     * null-on-failure contract as the read methods, so callers degrade
     * gracefully.
     *
     * Callers MUST treat this as a high-visibility operation: it is only
     * reachable through OpsActionService (allow-list, password + typed
     * confirmation, AdminAuditLog, Slack announcement). Nothing else in the
     * control plane may call it.
     *
     * @return array<string, mixed>|null
     */
    public function restartApplication(string $applicationUuid): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->baseUrl."/api/v1/applications/{$applicationUuid}/restart");

            if (! $response->successful()) {
                Log::warning('CoolifyApiClient: restart failed', [
                    'uuid' => $applicationUuid,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (Throwable $e) {
            Log::warning('CoolifyApiClient: restart request threw', [
                'uuid' => $applicationUuid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize the variety of list shapes Coolify returns (raw arrays,
     * {data: []} wrappers, {applications: []} ...).
     *
     * @param  array<int|string,mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    private function listOf(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        // Wrapped shapes.
        foreach (['data', 'applications', 'servers', 'databases', 'services', 'deployments'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload = $payload[$key];

                break;
            }
        }

        $items = [];
        foreach ($payload as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public function reachable(): bool
    {
        $teams = $this->get('/api/v1/teams');

        return $teams !== null;
    }

    public function servers(): array
    {
        return $this->listOf($this->get('/api/v1/servers'));
    }

    public function applications(): array
    {
        return $this->listOf($this->get('/api/v1/applications'));
    }

    public function databases(): array
    {
        return $this->listOf($this->get('/api/v1/databases'));
    }

    public function services(): array
    {
        return $this->listOf($this->get('/api/v1/services'));
    }

    public function applicationDeployments(string $applicationUuid): array
    {
        return $this->listOf($this->get("/api/v1/applications/{$applicationUuid}/deployments"));
    }

    public function deployment(string $deploymentUuid): ?array
    {
        $data = $this->get("/api/v1/deployments/{$deploymentUuid}");

        return $data;
    }

    public function applicationByUuid(string $applicationUuid): ?array
    {
        return $this->get("/api/v1/applications/{$applicationUuid}");
    }

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

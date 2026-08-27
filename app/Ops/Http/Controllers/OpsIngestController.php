<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OpsCenter — OpsIngestController.
 *
 * The ingestion API that makes the control plane PLATFORM-WIDE without
 * agents: the other applications on the Coolify server POST their
 * errors/events here with a shared token — no Docker socket, no SSH, no
 * inbound ports on the reporting side (ADR-3).
 *
 * POST /api/ops/ingest
 * Headers: X-Ops-Token: <token>   (token configured in OPS_INGEST_TOKENS)
 * Body (JSON):
 *   {
 *     "title":       "Database connection failure",      // required
 *     "message":     "SQLSTATE[HY000] [2002] ...",       // optional
 *     "severity":    "critical|error|warning|info",     // default: error
 *     "category":    "DATABASE",                         // optional (classified otherwise)
 *     "environment": "production",                       // default: production
 *     "context":     { ... }                             // optional, redacted server-side
 *   }
 * The application is derived from the TOKEN (each token maps to a slug in
 * OPS_INGEST_TOKENS) — a reporter can never claim to be another app.
 *
 * Security:
 *   - Fail-closed: no OPS_INGEST_TOKENS configured → 404 (the endpoint
 *     does not exist; same convention as MetricsController).
 *   - Timing-safe comparison (hash_equals) of a SHA-256 of the provided
 *     token against the configured value.
 *   - Rate-limited (default 30/min per IP).
 *   - Payload size caps (config/ops.php → ingest).
 *   - Server-side redaction ALWAYS runs — reporters cannot opt out.
 */
class OpsIngestController extends Controller
{
    public function __construct(
        private readonly OpsEventIngestor $ingestor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $credentials = $this->credentials();

        if ($credentials === []) {
            // Fail-closed: the ingest API is disabled until tokens exist.
            abort(404);
        }

        $token = (string) $request->header('X-Ops-Token', '');

        $slug = $this->authenticate($token, $credentials);

        if ($slug === null) {
            return response()->json([
                'error' => 'Invalid or missing X-Ops-Token header.',
            ], 401);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:250',
            'message' => 'nullable|string|max:'.(int) config('ops.ingest.max_message_length', 8000),
            'severity' => 'nullable|in:critical,error,warning,info',
            'category' => 'nullable|string|max:30',
            'environment' => 'nullable|string|max:50',
            'context' => 'nullable|array|max:64',
            'occurred_at' => 'nullable|date',
        ]);

        $context = $validated['context'] ?? [];
        if (strlen((string) json_encode($context)) > (int) config('ops.ingest.max_context_bytes', 16384)) {
            return response()->json([
                'error' => 'context exceeds the maximum size ('.config('ops.ingest.max_context_bytes').' bytes).',
            ], 422);
        }

        // The reporter's identity is the TOKEN's slug — never a client
        // claim. Name falls back to a title-cased slug.
        $application = $this->ingestor->resolveOrCreateApplication(
            $slug,
            ucwords(str_replace(['-', '_'], ' ', $slug)),
            $validated['environment'] ?? null,
        );

        $event = $this->ingestor->record([
            'source' => 'ingest',
            'severity' => $validated['severity'] ?? 'error',
            'category' => $validated['category'] ?? null,
            'title' => $validated['title'],
            'message' => $validated['message'] ?? '',
            'context' => $context,
            'application_id' => $application->id,
            'environment' => $validated['environment'] ?? 'production',
            'occurred_at' => $validated['occurred_at'] ?? null,
        ]);

        if ($event === null) {
            return response()->json(['error' => 'Ingestion failed — retry shortly.'], 503);
        }

        return response()->json([
            'id' => $event->id,
            'fingerprint' => $event->fingerprint,
            'occurrence_count' => $event->occurrence_count,
            'status' => $event->status,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Parse OPS_INGEST_TOKENS ("slug1=token1,slug2=token2").
     *
     * @return array<string, string> slug => configured token
     */
    private function credentials(): array
    {
        $raw = (string) config('ops.ingest.tokens', '');

        if (trim($raw) === '') {
            return [];
        }

        $credentials = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', trim((string) $pair), 2);
            if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $credentials[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $credentials;
    }

    /**
     * Timing-safe token check. Returns the application slug on success.
     */
    private function authenticate(string $provided, array $credentials): ?string
    {
        if ($provided === '') {
            return null;
        }

        $providedHash = hash('sha256', $provided);

        foreach ($credentials as $slug => $configured) {
            if (hash_equals(hash('sha256', $configured), $providedHash)) {
                return $slug;
            }
        }

        return null;
    }
}

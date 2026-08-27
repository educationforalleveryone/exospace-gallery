<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Support\ErrorClassifier;
use App\Ops\Support\LogRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OpsCenter — OpsEventIngestor.
 *
 * The SINGLE pipeline every event passes through, regardless of source:
 *
 *     app logs (tap) ──┐
 *     exceptions ──────┤
 *     Coolify sync ────┼──> record() ──> redact ─> classify ─> fingerprint
 *     ingest API ──────┤                                  └─> dedup upsert
 *     health checks ───┘
 *
 * Contracts:
 *   - Redaction happens HERE, closest to persistence. No caller needs to
 *     remember to redact (and forgetting is impossible).
 *   - Dedup by fingerprint: the same error recurring increments counters on
 *     the existing row. A storm of 37 identical DB errors = one row with
 *     occurrence_count=37.
 *   - Reopen semantics: a resolved event that recurs reopens with a fresh
 *     episode (occurrence_count=1) while total_count keeps accumulating.
 *   - NEVER throws to the caller: observability must not take the
 *     application down. Failures are swallowed after being written to
 *     stderr (never Log:: — the log tap would recurse).
 */
class OpsEventIngestor
{
    private bool $busy = false;

    public function __construct(
        private readonly LogRedactor $redactor,
        private readonly ErrorClassifier $classifier,
    ) {}

    /**
     * Record an event. Returns the persisted event, or null on failure.
     *
     * @param  array  $input  {
     *                        source:           string  — exception|app_log|coolify|ingest|health|heartbeat|backup|webhook|scheduler|system
     *                        category:         ?string — force a category (skips pattern match, still gets causes when known)
     *                        severity:         string  — observed severity (critical|error|warning|info)
     *                        title:            ?string — headline; derived when absent
     *                        message:          ?string — raw detail (redacted here)
     *                        context:          array   — structured context (redacted here)
     *                        application_slug: ?string — application identifier (slug/uuid)
     *                        application_id:   ?int    — direct ops_application_id
     *                        environment:      ?string
     *                        occurred_at:      ?\DateTimeInterface
     *                        }
     */
    public function record(array $input): ?OpsEvent
    {
        // Reentrancy guard: the DB error handler could itself log to a
        // channel that taps back into this ingestor.
        if ($this->busy) {
            return null;
        }

        $this->busy = true;

        try {
            $source = (string) ($input['source'] ?? 'system');
            $severityIn = strtolower((string) ($input['severity'] ?? 'error'));
            if (! in_array($severityIn, OpsEvent::SEVERITIES, true)) {
                $severityIn = 'error';
            }

            $message = $this->redactor->redactString((string) ($input['message'] ?? ''));
            $context = $this->redactor->redactContext($input['context'] ?? []);

            $classification = $this->classifier->classify(
                $input['exception_class'] ?? null,
                $message !== '' ? $message : (string) ($input['title'] ?? ''),
                $severityIn,
            );

            // A forced category (e.g. DEPLOYMENT from the Coolify sync) wins
            // over the pattern; the classifier still provides causes when it
            // matched something useful.
            $forcedCategory = isset($input['category'])
                && in_array((string) $input['category'], OpsEvent::CATEGORIES, true)
                ? (string) $input['category']
                : null;

            $category = $forcedCategory ?? $classification['category'];
            $severity = $forcedCategory !== null
                ? $severityIn // forced categories trust the caller's severity
                : $classification['severity'];

            $title = mb_substr(
                trim((string) ($input['title'] ?? '')) !== ''
                    ? (string) $input['title']
                    : $classification['title'],
                0,
                250,
            );
            $title = $this->redactor->redactString($title);

            $applicationId = $this->resolveApplicationId($input);

            $fingerprint = $this->fingerprint($applicationId, $category, $title, $message);
            $occurredAt = $input['occurred_at'] ?? now();

            return $this->upsertEvent(
                $fingerprint,
                $applicationId,
                $source,
                $category,
                $severity,
                $title,
                $message,
                is_array($context) ? $context : [],
                $classification + ($forcedCategory ? ['forced_category' => true] : []),
                (string) ($input['environment'] ?? app()->environment()),
                $occurredAt,
            );
        } catch (Throwable $e) {
            // stderr only — Log:: would recurse through the tap.
            fwrite(STDERR, '[OpsEventIngestor] failed: '.$e->getMessage().\PHP_EOL);

            return null;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Find-or-create the "self" application (the host app: Exospace).
     */
    public static function selfApplication(): OpsApplication
    {
        $name = (string) config('ops.self.name', 'This Application');
        $uuid = config('ops.self.coolify_uuid');

        $app = OpsApplication::where('is_self', true)->first();

        if ($app === null) {
            $app = OpsApplication::create([
                'slug' => 'self',
                'name' => $name,
                'provider' => 'self',
                'kind' => 'application',
                'environment' => (string) config('ops.self.environment', 'production'),
                'url' => config('ops.self.url'),
                'status' => 'running',
                'health' => 'running',
                'is_self' => true,
                'meta' => $uuid ? ['coolify_uuid' => $uuid] : null,
                'last_seen_at' => now(),
            ]);
        }

        // Keep identity fields in sync with config (rename/re-URL picked up).
        $app->fill([
            'name' => $name,
            'environment' => (string) config('ops.self.environment', 'production'),
            'url' => config('ops.self.url'),
        ]);
        if ($uuid) {
            $meta = $app->meta ?? [];
            $meta['coolify_uuid'] = $uuid;
            $app->meta = $meta;
        }
        if ($app->isDirty()) {
            $app->save();
        }

        return $app;
    }

    /**
     * Find-or-create an application by slug (used by the ingest API).
     */
    public function resolveOrCreateApplication(string $slug, string $name, ?string $environment = null): OpsApplication
    {
        return OpsApplication::firstOrCreate(
            ['slug' => mb_substr($slug, 0, 100)],
            [
                'name' => $name !== '' ? mb_substr($name, 0, 250) : mb_substr($slug, 0, 250),
                'provider' => 'ingest',
                'kind' => 'application',
                'environment' => $environment ?? 'production',
                'health' => 'unknown',
            ],
        );
    }

    private function resolveApplicationId(array $input): ?int
    {
        if (isset($input['application_id'])) {
            return (int) $input['application_id'];
        }

        if (! empty($input['application_slug'])) {
            $app = OpsApplication::where('slug', (string) $input['application_slug'])->first();

            return $app?->id;
        }

        // Default attribution: the host application (self).
        try {
            return self::selfApplication()->id;
        } catch (Throwable) {
            return null; // migrations not run yet — still record, unattributed
        }
    }

    private function upsertEvent(
        string $fingerprint,
        ?int $applicationId,
        string $source,
        string $category,
        string $severity,
        string $title,
        string $message,
        array $context,
        array $classification,
        string $environment,
        mixed $occurredAt,
    ): OpsEvent {
        return DB::transaction(function () use (
            $fingerprint, $applicationId, $source, $category, $severity,
            $title, $message, $context, $classification, $environment, $occurredAt,
        ) {
            $now = now();

            $event = OpsEvent::where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($event === null) {
                return OpsEvent::create([
                    'fingerprint' => $fingerprint,
                    'ops_application_id' => $applicationId,
                    'source' => $source,
                    'category' => $category,
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'occurrence_count' => 1,
                    'total_count' => 1,
                    'first_seen_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'status' => 'open',
                    'environment' => $environment,
                    'context' => $context,
                    'classification' => $classification,
                ]);
            }

            // Dedup hit: bump counters. If the event was resolved, this
            // recurrence REOPENS it with a fresh episode.
            $reopening = $event->status === 'resolved' || $event->status === 'acknowledged';

            $event->occurrence_count = $reopening ? 1 : $event->occurrence_count + 1;
            $event->total_count = $event->total_count + 1;
            $event->last_seen_at = $now;
            $event->status = 'open';
            $event->resolved_at = null;

            if ($reopening) {
                $event->first_seen_at = $now;
            }

            // Severity escalates but never de-escalates during an episode:
            // if it was ever critical while open, it stays critical.
            $severityRank = OpsEvent::severityRank($severity);
            if ($severityRank > OpsEvent::severityRank($event->severity)) {
                $event->severity = $severity;
            }

            // Keep the freshest message/context (the newest occurrence is
            // the most relevant for diagnosis).
            if ($message !== '') {
                $event->message = $message;
            }
            if ($context !== []) {
                $event->context = $context;
            }
            $event->classification = $classification;
            $event->environment = $environment;

            $event->save();

            return $event;
        });
    }

    /**
     * Stable grouping key: same application + category + normalized title.
     *
     * Normalization strips digits, UUIDs and quoted values so that
     * "Failed for order 12345" and "Failed for order 99999" group together
     * (they are the same operational problem), while different titles stay
     * distinct. Message is intentionally NOT part of the key — titles are
     * classifier-driven and stable; messages carry per-occurrence noise.
     */
    private function fingerprint(?int $applicationId, string $category, string $title, string $message): string
    {
        $normalized = strtolower($title);
        $normalized = preg_replace('/[0-9]+/', '#', $normalized) ?? $normalized;
        $normalized = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', 'uuid', $normalized) ?? $normalized;
        $normalized = preg_replace('/"([^"]*)"/', '"x"', $normalized) ?? $normalized;
        $normalized = preg_replace('/\'([^\']*)\'/', "'x'", $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return hash('sha256', ($applicationId ?? 0).'|'.$category.'|'.trim($normalized));
    }
}

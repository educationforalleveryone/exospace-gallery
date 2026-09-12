<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Support\ErrorClassifier;
use App\Ops\Support\LogRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpsEventIngestor
{
    private bool $busy = false;

    public function __construct(
        private readonly LogRedactor $redactor,
        private readonly ErrorClassifier $classifier,
    ) {}

    public function record(array $input): ?OpsEvent
    {
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

    public function correlateCritical(OpsEvent $event): void
    {
        if ($event->severity !== 'critical' || $event->ops_incident_id !== null) {
            return;
        }

        try {
            app(IncidentCorrelationService::class)->correlate($event);
        } catch (Throwable $e) {
            fwrite(STDERR, '[OpsEventIngestor] correlation skipped: '.$e->getMessage().\PHP_EOL);
        }
    }

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

            $reopening = $event->status === 'resolved' || $event->status === 'acknowledged';

            $event->occurrence_count = $reopening ? 1 : $event->occurrence_count + 1;
            $event->total_count = $event->total_count + 1;
            $event->last_seen_at = $now;
            $event->status = 'open';
            $event->resolved_at = null;

            if ($reopening) {
                $event->first_seen_at = $now;
            }

            $severityRank = OpsEvent::severityRank($severity);
            if ($severityRank > OpsEvent::severityRank($event->severity)) {
                $event->severity = $severity;
            }

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

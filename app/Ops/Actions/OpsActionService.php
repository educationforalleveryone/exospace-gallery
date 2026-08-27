<?php

declare(strict_types=1);

namespace App\Ops\Actions;

use App\Http\Controllers\WebhookController;
use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Services\CoolifyApiClient;
use App\Ops\Services\OpsEventIngestor;
use App\Ops\Services\PlatformSyncService;
use App\Services\ArtisanCommandRunner;
use App\Services\OperationalAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OpsCenter — OpsActionService (Iteration 3).
 *
 * Executes allow-listed actions after the CONTROLLER has validated the
 * operator's password and typed confirmation phrase. This service is the
 * only code path to Coolify's restart endpoint and the only OpsCenter
 * trigger for the webhook replay pipeline — both reused systems, never
 * duplicated (ADR-6).
 *
 * Every action, success or failure:
 *   - is recorded in AdminAuditLog (ops.action.executed) with actor,
 *     target and outcome;
 *   - is announced through OperationalAlertService (the existing alerting
 *     pipeline — its dedup and severity routing are inherited);
 *   - creates a control-plane EVENT (so restarts/replays appear in
 *     timelines and incident correlation);
 *   - returns a structured result — it NEVER throws to the caller.
 *
 * The kill switch (config ops.actions.enabled, env OPS_ACTIONS_ENABLED,
 * default true) fail-closes the whole surface: execute() refuses and the
 * UI hides every action.
 */
class OpsActionService
{
    public function __construct(
        private readonly CoolifyApiClient $coolify,
        private readonly PlatformSyncService $sync,
        private readonly OpsEventIngestor $ingestor,
        private readonly OperationalAlertService $alerts,
        private readonly ArtisanCommandRunner $artisan,
    ) {}

    /**
     * Is the action surface available at all?
     */
    public function enabled(): bool
    {
        return (bool) config('ops.actions.enabled', true);
    }

    /**
     * Execute an allow-listed action.
     *
     * @param  string  $actionId  Registry id (app.restart | webhook.replay | platform.sync | queue.retry | queue.forget).
     * @param  array{application_id?: ?int, webhook_id?: ?int, failed_job_uuid?: ?string}  $target
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    public function execute(string $actionId, array $target, User $actor): array
    {
        $definition = OpsActionRegistry::get($actionId);

        if ($definition === null) {
            return ['ok' => false, 'message' => 'Unknown action — not in the allow-list.'];
        }

        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'Ops actions are disabled on this deployment (OPS_ACTIONS_ENABLED=false).'];
        }

        try {
            $result = match ($actionId) {
                'app.restart' => $this->restartApplication($target, $actor),
                'webhook.replay' => $this->replayWebhook($target, $actor),
                'platform.sync' => $this->platformSync($actor),
                'queue.retry' => $this->retryFailedJob($target, $actor),
                'queue.forget' => $this->forgetFailedJob($target, $actor),
                default => ['ok' => false, 'message' => 'Action is declared but not implemented.'],
            };
        } catch (Throwable $e) {
            $result = [
                'ok' => false,
                'message' => 'The action failed unexpectedly: '.mb_substr($e->getMessage(), 0, 300),
            ];
        }

        $this->audit($actionId, $definition, $target, $actor, $result);
        $this->announce($actionId, $definition, $actor, $result);

        return $result;
    }

    // ── Actions ─────────────────────────────────────────────────────────

    /**
     * Restart an application container via the Coolify API.
     *
     * @param  array{application_id?: ?int}  $target
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    private function restartApplication(array $target, User $actor): array
    {
        $application = OpsApplication::find((int) ($target['application_id'] ?? 0));

        if ($application === null) {
            return ['ok' => false, 'message' => 'Application not found.'];
        }

        $uuid = (string) ($application->provider === 'coolify'
            ? ($application->provider_uuid ?? '')
            : ($application->meta['coolify_uuid'] ?? ''));

        if ($uuid === '') {
            return [
                'ok' => false,
                'message' => '"'.$application->name.'" has no Coolify resource UUID — only Coolify-managed applications can be restarted from here.',
            ];
        }

        if (! $this->coolify->isConfigured()) {
            return ['ok' => false, 'message' => 'The Coolify API is not configured (COOLIFY_API_TOKEN / COOLIFY_API_BASE_URL). Restart is unavailable.'];
        }

        $response = $this->coolify->restartApplication($uuid);

        if ($response === null) {
            return [
                'ok' => false,
                'message' => 'Coolify rejected or failed the restart request for "'.$application->name.'". The application was NOT restarted. Check the Coolify UI for details (the API may have refused the request).',
            ];
        }

        // Observable in the timeline + incident correlation: the operator
        // intervened. Severity info — this is a deliberate change, not a
        // fault; the app's own events will tell the rest of the story.
        $this->ingestor->record([
            'source' => 'system',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'info',
            'title' => 'Container restart triggered from the control plane — '.$application->name,
            'message' => 'An operator (user #'.$actor->id.', super-admin) restarted the "'.$application->name.'" container through OpsCenter (same image, no rebuild). Expect brief downtime while the container cycles.',
            'application_id' => $application->id,
            'context' => [
                'actor_id' => $actor->id,
                'coolify_uuid' => $uuid,
                'coolify_response' => $response,
            ],
        ]);

        $deploymentUuid = is_array($response) && isset($response['deployment_uuid'])
            ? (string) $response['deployment_uuid']
            : null;

        return [
            'ok' => true,
            'message' => 'Restart requested for "'.$application->name.'" — Coolify is stopping the current container and starting a fresh one from the same image. The application will be briefly unavailable; watch Container health to confirm it comes back.',
            'detail' => [
                'application' => $application->name,
                'coolify_deployment_uuid' => $deploymentUuid,
            ],
        ];
    }

    /**
     * Replay one stored billing webhook through the EXISTING pipeline (the
     * same code path Master Control's Billing Review uses — reused, not
     * duplicated).
     *
     * @param  array{webhook_id?: ?int}  $target
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    private function replayWebhook(array $target, User $actor): array
    {
        $row = ProcessedWebhook::find((int) ($target['webhook_id'] ?? 0));

        if ($row === null) {
            return ['ok' => false, 'message' => 'Webhook row not found.'];
        }

        if (! $row->payload) {
            return ['ok' => false, 'message' => 'Webhook #'.$row->id.' has no stored payload — replay is not possible.'];
        }

        $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;

        if (! is_array($payload)) {
            return ['ok' => false, 'message' => 'Webhook #'.$row->id.' has a corrupted stored payload — replay is not possible.'];
        }

        // Identical semantics to SuperAdmin\BillingController::replayWebhook:
        // synthetic request through the live pipeline, no re-verification,
        // no dedupe — safety comes from the handlers' idempotency.
        $synthetic = Request::create('/webhooks/2checkout', 'POST', $payload);

        $ok = false;
        $httpStatus = 500;

        try {
            $response = app(WebhookController::class)->processReplay($synthetic);
            $httpStatus = $response->status();
            $ok = $httpStatus < 500;
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error('OpsActionService: webhook replay threw', [
                'webhook_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }

        $row->update([
            'status' => $ok ? 'processed' : 'failed',
            'replay_count' => ((int) ($row->replay_count ?? 0)) + 1,
            'last_replayed_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Replayed webhook #'.$row->id.' ('.$row->message_type.') — the pipeline returned '.$httpStatus.'. The ledger row is marked processed.'
                : 'Replay of webhook #'.$row->id.' returned '.$httpStatus.' — the ledger row stays marked failed; inspect the application logs for the handler error.',
            'detail' => [
                'webhook_id' => $row->id,
                'message_type' => $row->message_type,
                'invoice_id' => $row->invoice_id,
                'http_status' => $httpStatus,
                'replay_number' => (int) ($row->replay_count ?? 0) + 1,
            ],
        ];
    }

    /**
     * Run the scheduled platform sync on demand (same semantics as
     * ops:sync-platform — idempotent, non-fatal on API failure).
     *
     * Unreachability is detected with an explicit reachability probe
     * (GET /teams): sync() itself degrades every endpoint failure to
     * empty data, so a dead API would otherwise look like an empty-but-
     * successful refresh.
     *
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    private function platformSync(User $actor): array
    {
        if (! config('ops.platform_sync.enabled')) {
            return ['ok' => false, 'message' => 'Platform sync is disabled (OPS_PLATFORM_SYNC_ENABLED=false).'];
        }

        if (! $this->coolify->isConfigured()) {
            return ['ok' => false, 'message' => 'Coolify API not configured (COOLIFY_API_TOKEN / COOLIFY_API_BASE_URL missing).'];
        }

        if (! $this->coolify->reachable()) {
            $this->sync->recordApiUnreachable();

            return ['ok' => false, 'message' => 'The Coolify API is unreachable right now — an infrastructure event was recorded (rate-limited, as the scheduled sync does).'];
        }

        $result = $this->sync->sync();

        if (! $result['api_ok']) {
            return ['ok' => false, 'message' => 'The sync did not complete (API configuration rejected).'];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'Platform sync completed — %d resources refreshed, %d new event(s) created.',
                $result['applications'],
                $result['events_created'],
            ),
            'detail' => $result,
        ];
    }

    // ── Iteration 10 — the queue lifecycle ─────────────────────────────

    /**
     * Look up ONE failed job by its UUID (the stable public identifier —
     * numeric ids renumber on table rebuilds; UUIDs are also what
     * queue:retry itself accepts).
     *
     * @return array{id: int, uuid: string, connection: string, queue: string, payload: string, exception: string, failed_at: string}|null
     */
    private function findFailedJob(?string $uuid): ?array
    {
        if ($uuid === null || $uuid === '' || strlen($uuid) > 64) {
            return null;
        }

        try {
            $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();

            return $row === null ? null : [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'payload' => (string) $row->payload,
                'exception' => (string) $row->exception,
                'failed_at' => (string) $row->failed_at,
            ];
        } catch (Throwable) {
            // failed_jobs table missing/unreadable — same answer as "not found",
            // the caller's message will carry the reasonableness.
            return null;
        }
    }

    /**
     * The human-facing job name from the payload (displayName), falling
     * back to the class name, falling back to "unknown" — the raw payload
     * is JSON with a displayName key in every Laravel-dispatched job.
     */
    public static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            if (! empty($decoded['displayName']) && is_string($decoded['displayName'])) {
                return mb_substr($decoded['displayName'], 0, 120);
            }

            if (! empty($decoded['data']['commandName']) && is_string($decoded['data']['commandName'])) {
                return mb_substr($decoded['data']['commandName'], 0, 120);
            }
        }

        return 'Unknown job';
    }

    /**
     * Retry ONE failed job through Laravel's own queue:retry (the same
     * command the terminal used to be needed for — reused, not
     * duplicated). Laravel pushes the payload back onto the job's
     * original connection and deletes the failed row.
     *
     * SUCCESS IS VERIFIED AGAINST THE TABLE, NOT THE EXIT CODE: Laravel's
     * retry order is push-then-forget, so a row that is GONE afterwards
     * means the push happened; a row that SURVIVES means it did not —
     * whatever the exit code says (an unserializable payload or a dead
     * queue connection both leave the row in place, and the command's
     * exit code is 0 in several of those cases).
     *
     * @param  array{failed_job_uuid?: ?string}  $target
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    private function retryFailedJob(array $target, User $actor): array
    {
        $uuid = (string) ($target['failed_job_uuid'] ?? '');
        $job = $this->findFailedJob($uuid);

        if ($job === null) {
            return ['ok' => false, 'message' => 'No failed job with UUID "'.mb_substr($uuid, 0, 40).'" — it may have already been retried or forgotten. Refresh the queue page.'];
        }

        $jobName = self::jobName($job['payload']);

        $exit = ($this->artisan)('queue:retry', ['id' => [$job['uuid']]]);

        $gone = $this->findFailedJob($uuid) === null;

        if (! $gone) {
            $tail = trim(mb_substr($this->artisan->lastOutput(), -300));

            return [
                'ok' => false,
                'message' => 'The retry did not complete — the job is still in the failed list (Laravel refused or failed to re-dispatch it; exit code '.$exit.').'.($tail !== '' ? ' Command output tail: '.$tail : ''),
                'detail' => ['job' => $jobName, 'queue' => $job['queue'], 'uuid' => $job['uuid'], 'exit_code' => $exit],
            ];
        }

        // Same observability as app.restart: the operator intervened —
        // QUEUE/info, so the deliberate retry shows in timelines.
        $this->ingestor->record([
            'source' => 'system',
            'category' => 'QUEUE',
            'severity' => 'info',
            'title' => 'Failed job retried from the control plane — '.$jobName,
            'message' => 'An operator (user #'.$actor->id.', super-admin) retried the failed job "'.$jobName.'" on queue "'.$job['queue'].'" through OpsCenter (job uuid: '.$job['uuid'].'). The payload was pushed back onto the "'.$job['connection'].'" connection and removed from the failed list.',
            'context' => [
                'actor_id' => $actor->id,
                'job_uuid' => $job['uuid'],
                'queue' => $job['queue'],
                'connection' => $job['connection'],
                'job' => $jobName,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Retry dispatched — "'.$jobName.'" is back on queue "'.$job['queue'].'" with its retry counter reset (job uuid: '.$job['uuid'].'). Watch the queue diagnostics: if the underlying cause is still present, the job will fail back into the list.',
            'detail' => ['job' => $jobName, 'queue' => $job['queue'], 'uuid' => $job['uuid']],
        ];
    }

    /**
     * Forget (permanently delete) ONE failed job through Laravel's own
     * queue:forget. The row, its payload and its exception trace are
     * gone — there is no archive.
     *
     * Same authoritative row-verification as retry.
     *
     * @param  array{failed_job_uuid?: ?string}  $target
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    private function forgetFailedJob(array $target, User $actor): array
    {
        $uuid = (string) ($target['failed_job_uuid'] ?? '');
        $job = $this->findFailedJob($uuid);

        if ($job === null) {
            return ['ok' => false, 'message' => 'No failed job with UUID "'.mb_substr($uuid, 0, 40).'" — it may already be gone. Refresh the queue page.'];
        }

        $jobName = self::jobName($job['payload']);

        $exit = ($this->artisan)('queue:forget', ['id' => $job['uuid']]);

        $gone = $this->findFailedJob($uuid) === null;

        if (! $gone) {
            return [
                'ok' => false,
                'message' => 'The delete did not complete — the job is still in the failed list (exit code '.$exit.'). The failed-jobs table may not be writable.',
                'detail' => ['job' => $jobName, 'queue' => $job['queue'], 'uuid' => $job['uuid'], 'exit_code' => $exit],
            ];
        }

        $this->ingestor->record([
            'source' => 'system',
            'category' => 'QUEUE',
            'severity' => 'info',
            'title' => 'Failed job deleted from the control plane — '.$jobName,
            'message' => 'An operator (user #'.$actor->id.', super-admin) permanently deleted the failed job "'.$jobName.'" on queue "'.$job['queue'].'" through OpsCenter (queue:forget; job uuid: '.$job['uuid'].'). The payload and exception trace no longer exist anywhere.',
            'context' => [
                'actor_id' => $actor->id,
                'job_uuid' => $job['uuid'],
                'queue' => $job['queue'],
                'job' => $jobName,
            ],
        ]);

        return [
            'ok' => true,
            'message' => 'Deleted — "'.$jobName.'" is permanently removed from the failed list (job uuid: '.$job['uuid'].'). Payload and exception included; there is no archive. The counts in the digest and diagnostics drop on their next pass.',
            'detail' => ['job' => $jobName, 'queue' => $job['queue'], 'uuid' => $job['uuid']],
        ];
    }

    // ── Recording ───────────────────────────────────────────────────────

    /**
     * Audit every executed action attempt (append-only ledger, PII-hashed by
     * AdminAuditLog itself — actor email lands in the payload only as a
     * hashed value).
     *
     * Queue actions (Iteration 10) audit against the control-plane host
     * application (the fail-soft selfApplication fallback) — failed jobs
     * are not Eloquent models, and they belong to the platform the control
     * plane itself runs in. The UUID rides in the message.
     *
     * @param  array{application_id?: ?int, webhook_id?: ?int, failed_job_uuid?: ?string}  $target
     */
    private function audit(string $actionId, array $definition, array $target, User $actor, array $result): void
    {
        try {
            $auditTarget = $this->resolveAuditTarget($actionId, $target)
                ?? $this->ingestor::selfApplication();

            AdminAuditLog::record('ops.action.executed', $auditTarget, [
                'action' => $actionId,
                'risk' => $definition['risk'],
                'outcome' => $result['ok'] ? 'success' : 'failure',
                'message' => mb_substr((string) $result['message'], 0, 400),
                // actor identity comes from Auth::id() inside AdminAuditLog —
                // no email (or other PII) is added by this payload.
            ]);
        } catch (Throwable) {
            // The audit ledger must never take an action's success path down.
        }
    }

    /**
     * Announce through the EXISTING alerting pipeline (info severity for
     * successes, error for failures — both visible in the ops Slack channel;
     * no dedup key: operator actions are rare and each one matters).
     */
    private function announce(string $actionId, array $definition, User $actor, array $result): void
    {
        try {
            $this->alerts->alert(
                'OpsCenter action: '.$definition['label'],
                sprintf(
                    "%s executed `%s` — outcome: %s\n%s",
                    'operator #'.$actor->id,
                    $actionId,
                    $result['ok'] ? 'SUCCESS' : 'FAILURE',
                    (string) $result['message'],
                ),
                $result['ok'] ? 'info' : 'error',
            );
        } catch (Throwable) {
            // Alerting must never take the action result down.
        }
    }

    /**
     * @param  array{application_id?: ?int, webhook_id?: ?int, failed_job_uuid?: ?string}  $target
     */
    private function resolveAuditTarget(string $actionId, array $target): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($actionId) {
            'app.restart' => OpsApplication::find((int) ($target['application_id'] ?? 0)),
            'webhook.replay' => ProcessedWebhook::find((int) ($target['webhook_id'] ?? 0)),
            default => null, // platform.sync + queue.* → selfApplication fallback
        };
    }
}

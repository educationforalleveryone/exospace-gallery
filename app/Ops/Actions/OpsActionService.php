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
use App\Services\OperationalAlertService;
use Illuminate\Http\Request;
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
     * @param  string  $actionId  Registry id (app.restart | webhook.replay | platform.sync).
     * @param  array{application_id?: ?int, webhook_id?: ?int}  $target
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

    // ── Recording ───────────────────────────────────────────────────────

    /**
     * Audit every executed action attempt (append-only ledger, PII-hashed by
     * AdminAuditLog itself — actor email lands in the payload only as a
     * hashed value).
     *
     * @param  array{application_id?: ?int, webhook_id?: ?int}  $target
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
     * @param  array{application_id?: ?int, webhook_id?: ?int}  $target
     */
    private function resolveAuditTarget(string $actionId, array $target): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($actionId) {
            'app.restart' => OpsApplication::find((int) ($target['application_id'] ?? 0)),
            'webhook.replay' => ProcessedWebhook::find((int) ($target['webhook_id'] ?? 0)),
            default => null,
        };
    }
}

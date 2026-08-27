<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\CoolifyApiClient;
use Throwable;

/**
 * OpsCenter — DeploymentDiagnostics (Iteration 3).
 *
 * deployment.recent
 *
 * Answers the operator's "did something change right before it broke?" by
 * combining two sources:
 *   1) the LIVE deployments list from the Coolify API (status, commit,
 *      duration — tolerant of version differences, every field optional);
 *   2) the DEPLOYMENT/BUILD events the control plane has captured (these
 *      are the failures that already became incidents/events).
 *
 * Read-only: GETs only. Deploying or rolling back happens through Coolify —
 * this control plane never triggers deployments (restart of the CURRENT
 * image is the only infrastructure action it exposes, on the Actions page).
 */
class DeploymentDiagnostics implements RunsDiagnostics
{
    public function __construct(
        private readonly CoolifyApiClient $coolify,
    ) {}

    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        $target = $application ?? $this->selfApplication();

        if ($target === null) {
            return DiagnosticResult::inconclusive(
                'No application context',
                'This diagnostic needs an application (or runs against the control plane host by default). No application could be resolved.',
            );
        }

        $uuid = $target->provider === 'coolify' ? $target->provider_uuid : ($target->meta['coolify_uuid'] ?? null);
        $findings = [];

        // 1) Live deployments from Coolify.
        $live = [];
        $apiFailed = false;

        if ($uuid !== null && $uuid !== '') {
            try {
                $limit = max(1, (int) config('ops.platform_sync.deployments_limit', 5));
                $live = array_slice($this->coolify->applicationDeployments((string) $uuid), 0, $limit + 3);
            } catch (Throwable $e) {
                $apiFailed = true;
                $findings[] = [
                    'label' => 'Live deployment list',
                    'status' => 'skip',
                    'detail' => 'Coolify API unreachable: '.mb_substr($e->getMessage(), 0, 150).'. Showing captured events only.',
                ];
            }
        }

        if ($live !== []) {
            $shown = 0;
            $failedRecently = false;

            foreach ($live as $deployment) {
                if ($shown >= 5) {
                    break;
                }
                $status = strtolower((string) ($deployment['status'] ?? 'unknown'));
                $commit = (string) ($deployment['commit'] ?? '');
                $duration = isset($deployment['duration']) && is_numeric($deployment['duration'])
                    ? round((float) $deployment['duration']).'s'
                    : null;
                $when = isset($deployment['created_at']) ? $this->describeTimestamp((string) $deployment['created_at']) : 'unknown time';

                if (in_array($status, ['failed', 'cancelled'], true)) {
                    $failedRecently = true;
                }

                $findings[] = [
                    'label' => 'Deployment '.($deployment['deployment_uuid'] ?? ($deployment['uuid'] ?? '#'.($shown + 1))),
                    'status' => match ($status) {
                        'failed', 'cancelled' => 'fail',
                        'in_progress', 'queued' => 'warn',
                        default => 'pass',
                    },
                    'detail' => sprintf(
                        '%s%s — %s%s',
                        $status,
                        $commit !== '' ? ', commit '.substr($commit, 0, 10) : '',
                        $when,
                        $duration !== null ? ', took '.$duration : '',
                    ),
                ];
                $shown++;
            }

            if ($failedRecently) {
                $findings[] = [
                    'label' => 'Build/deploy failures',
                    'status' => 'fail',
                    'detail' => 'At least one recent deployment FAILED. The build log (Composer/npm/Docker output) lives in Coolify — open the deployment there for the full log.',
                ];
            }
        } elseif ($uuid === null || $uuid === '') {
            $findings[] = [
                'label' => 'Live deployment list',
                'status' => 'skip',
                'detail' => 'This application is not linked to a Coolify resource (no UUID) — deployments cannot be listed for it.',
            ];
        } elseif (! $apiFailed) {
            $findings[] = [
                'label' => 'Live deployment list',
                'status' => 'skip',
                'detail' => 'Coolify returned no deployments for this application (the deployments endpoint is unavailable on some Coolify versions — the captured events below remain authoritative).',
            ];
        }

        // 2) Captured deployment/build events (control-plane view).
        $events = collect();
        try {
            $events = OpsEvent::query()
                ->where('ops_application_id', $target->id)
                ->whereIn('category', ['DEPLOYMENT', 'BUILD'])
                ->whereIn('status', ['open', 'acknowledged'])
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get();
        } catch (Throwable) {
            // events unavailable — skipped below.
        }

        $findings[] = $events->isEmpty()
            ? [
                'label' => 'Captured deployment events',
                'status' => 'pass',
                'detail' => 'No unresolved deployment/build failures captured by the control plane.',
            ]
            : [
                'label' => 'Captured deployment events',
                'status' => 'fail',
                'detail' => sprintf('%d unresolved: %s', $events->count(), $events->take(2)->pluck('title')->map(fn ($t) => '"'.mb_substr($t, 0, 90).'"')->implode('; ')),
            ];

        $failed = $events->isNotEmpty() || collect($findings)->contains(fn ($f) => $f['status'] === 'fail');

        return DiagnosticResult::fromFindings(
            $failed
                ? 'Recent deployment problems found'
                : sprintf('Last %d deployment(s) reviewed — no failures', max(1, count($live))),
            $findings,
            $failed
                ? 'There are recent failed or cancelled deployments for this application. Deploy failures explain "it worked yesterday" better than anything else: the code that SHOULD be live is not (the old container keeps running), or a fresh migration shipped with the deploy and failed mid-way. Open the failing deployment in Coolify for the full build log (Composer, npm and Docker output); the linked event pages show what the control plane captured at the time.'
                : 'Recent deployments completed without failures. If errors started anyway, the trigger is more likely runtime-side (data shape, external service, load) than the deploy itself — though a SUCCESSFUL deploy can still carry a migration or config change that behaves differently in production. Cross-check the error events\' "what changed" panel.',
            $failed ? ['database.migration-status', 'container.health'] : ['container.health'],
        );
    }

    private function selfApplication(): ?OpsApplication
    {
        try {
            return \App\Ops\Services\OpsEventIngestor::selfApplication();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Coolify timestamps appear in several shapes across versions — describe
     * them best-effort, never fail.
     */
    private function describeTimestamp(string $value): string
    {
        try {
            $numeric = is_numeric($value) ? (int) $value : null;

            if ($numeric !== null) {
                // Heuristic: seconds vs milliseconds.
                if ($numeric > 10 ** 12) {
                    $numeric = (int) ($numeric / 1000);
                }

                return date('M j, H:i', $numeric);
            }

            $parsed = \Illuminate\Support\Carbon::parse($value);

            return $parsed->format('M j, H:i');
        } catch (Throwable) {
            return mb_substr($value, 0, 30);
        }
    }
}

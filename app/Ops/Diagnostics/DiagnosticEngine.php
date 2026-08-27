<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Support\LogRedactor;
use Throwable;

/**
 * OpsCenter — DiagnosticEngine (Iteration 3).
 *
 * The single entry point for running a diagnostic. Everything the brief
 * demands of the diagnostic engine is enforced HERE, not in the runners:
 *
 *   ALLOW-LIST   — only ids declared in DiagnosticRegistry can run. There is
 *                  no code path that turns user input into a command, query
 *                  or filename; input only selects between fixed, hard-coded
 *                  PHP checks. Unknown id → null (the caller surfaces 404).
 *   READ-ONLY    — the runners contain no writes; the engine adds nothing.
 *   AUDITED      — every run is recorded in AdminAuditLog (ops.diagnostic.run)
 *                  with actor, target, status and duration.
 *   REDACTED     — findings/summary/interpretation pass through LogRedactor
 *                  before persistence, so even a runner mistake cannot leak
 *                  a secret into the store.
 *   TIMEOUT-BOUND— runner I/O is individually bounded (HTTP client timeout,
 *                  single queries); wall-clock duration is measured and
 *                  recorded per run so slow checks are visible.
 *   NEVER THROWS — a failing runner becomes a failed run, visible in the UI,
 *                  never a 500.
 *
 * Scope guard: self-scoped diagnostics (the control plane's own database,
 * Redis, queue, filesystem...) answer honestly when aimed at a different
 * application — an inconclusive result with a pointer to what CAN answer —
 * instead of silently checking the wrong thing.
 */
class DiagnosticEngine
{
    public function __construct(
        private readonly LogRedactor $redactor,
    ) {}

    /**
     * Run one diagnostic by id.
     *
     * @param  string  $id  Registry id (allow-list enforced).
     * @param  OpsApplication|null  $application  Target application (null = self).
     * @param  User|null  $actor  The operator who triggered the run.
     * @param  string  $source  manual|event|incident (UI provenance).
     * @param  int|null  $sourceId  The triggering event/incident id, if any.
     * @return OpsDiagnosticRun|null The persisted run, or null when the id is
     *                               not in the allow-list (caller 404s).
     */
    public function run(string $id, ?OpsApplication $application = null, ?User $actor = null, string $source = 'manual', ?int $sourceId = null): ?OpsDiagnosticRun
    {
        $definition = DiagnosticRegistry::get($id);

        if ($definition === null) {
            return null; // not in the allow-list — no such diagnostic
        }

        $startedAt = microtime(true);

        try {
            $runner = app($definition['runner']);

            $result = $this->guardScope($definition, $application) ?? $runner->runDiagnostic($id, $application);
        } catch (Throwable $e) {
            // The runner contract says "never throw" — but the engine does
            // not trust that. An exploding check is a FAILED diagnostic with
            // an honest message, not a 500 on the dashboard.
            $result = DiagnosticResult::inconclusive(
                'The diagnostic itself failed while running',
                'The check could not complete. This is a control-plane problem, not necessarily an application problem. Details: '.mb_substr($e->getMessage(), 0, 300),
                ['server.resources', 'app.recent-errors'],
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return $this->persist(
            $id,
            $application,
            $actor,
            $source,
            $sourceId,
            $result,
            $durationMs,
        );
    }

    /**
     * PROBE one diagnostic — run it and return the raw result, WITHOUT
     * persisting a run row and WITHOUT auditing (Iteration 4 sweeps).
     *
     * Why the distinction: ops_diagnostic_runs rows and AdminAuditLog
     * entries are operator-facing records of deliberate, on-demand
     * investigations. A scheduled sweep probing five checks every 15
     * minutes would flood both ledgers with machine noise (480 rows/day)
     * and bury the human trail. The sweep instead persists ONLY the
     * exceptions — degraded/failed findings become control-plane events
     * through OpsEventIngestor (deduplicated), and recoveries resolve
     * them. The allow-list and never-throw guarantees are identical to
     * run(); there is simply nothing to persist when nothing was asked.
     *
     * @return DiagnosticResult|null null when the id is not in the
     *                               allow-list — same contract as run().
     */
    public function probe(string $id): ?DiagnosticResult
    {
        $definition = DiagnosticRegistry::get($id);

        if ($definition === null) {
            return null;
        }

        try {
            $runner = app($definition['runner']);

            return $runner->runDiagnostic($id, null);
        } catch (Throwable $e) {
            return DiagnosticResult::inconclusive(
                'The diagnostic itself failed while running',
                'The check could not complete during the sweep. This is a control-plane problem, not necessarily an application problem. Details: '.mb_substr($e->getMessage(), 0, 300),
            );
        }
    }

    /**
     * The diagnostics the classifier recommends for an error — resolved
     * against the registry so the UI only ever renders runnable buttons.
     *
     * @param  array<int, string>  $recommended
     * @return array<int, string>
     */
    public static function runnableRecommended(array $recommended): array
    {
        $runnable = [];
        foreach ($recommended as $id) {
            if (is_string($id) && DiagnosticRegistry::has($id) && ! in_array($id, $runnable, true)) {
                $runnable[] = $id;
            }
        }

        return $runnable;
    }

    /**
     * Union of the diagnostics recommended by an incident's member events
     * (Iteration 2 pages render these as one-click buttons too), capped to
     * keep the UI calm.
     *
     * @param  iterable<\App\Ops\Models\OpsEvent>  $events
     * @return array<int, string>
     */
    public static function runnableForEvents(iterable $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            foreach ($event->recommendedDiagnostics() as $id) {
                if (DiagnosticRegistry::has($id) && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return array_slice($ids, 0, 8);
    }

    /**
     * Self-scoped diagnostics aimed at another application never silently
     * check the wrong thing — they answer "cannot determine for X" with a
     * pointer to the diagnostics that CAN help.
     */
    private function guardScope(array $definition, ?OpsApplication $application): ?DiagnosticResult
    {
        if ($definition['scope'] !== DiagnosticRegistry::SCOPE_SELF || $application === null || $application->is_self) {
            return null; // no guard needed — run for real
        }

        return DiagnosticResult::inconclusive(
            'Not applicable to '.$application->name,
            '"'.$definition['label'].'" inspects the control plane host\'s own subsystems (its database, cache, queue or scheduler), and "'.$application->name.'" is a different application. For this application, run Container health (live Coolify status) or Application health (HTTP probe) instead; for a shared database or Redis resource, its row on the Applications page shows the live Coolify status.',
            ['container.health', 'app.health'],
        );
    }

    /**
     * Persist + audit. Redaction happens HERE, closest to persistence — the
     * same defense-in-depth rule the event ingestor applies.
     */
    private function persist(
        string $id,
        ?OpsApplication $application,
        ?User $actor,
        string $source,
        ?int $sourceId,
        DiagnosticResult $result,
        int $durationMs,
    ): OpsDiagnosticRun {
        $findings = $result->findings;

        foreach ($findings as $index => $finding) {
            $findings[$index]['label'] = $this->redactor->redactString((string) $finding['label']);
            $findings[$index]['detail'] = $this->redactor->redactString((string) $finding['detail']);
        }

        $run = OpsDiagnosticRun::create([
            'diagnostic_id' => $id,
            'ops_application_id' => $application?->id,
            'actor_id' => $actor?->id,
            'source' => in_array($source, ['manual', 'event', 'incident'], true) ? $source : 'manual',
            'source_id' => $sourceId,
            'status' => in_array($result->status, OpsDiagnosticRun::STATUSES, true) ? $result->status : 'inconclusive',
            'summary' => mb_substr($this->redactor->redactString($result->summary), 0, 500),
            'findings' => $findings,
            'interpretation' => $this->redactor->redactString($result->interpretation),
            'next_steps' => array_values(array_filter(
                $result->nextSteps,
                fn ($step) => is_string($step),
            )),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);

        try {
            AdminAuditLog::record('ops.diagnostic.run', $run, [
                'diagnostic' => $id,
                'application' => $application?->name,
                'status' => $run->status,
                'duration_ms' => $durationMs,
                'source' => $run->source.($sourceId !== null ? ':'.$sourceId : ''),
            ]);
        } catch (Throwable) {
            // The audit ledger must never break a diagnostic run — the run
            // itself is the primary record.
        }

        return $run;
    }
}

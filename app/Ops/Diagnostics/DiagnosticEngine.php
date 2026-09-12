<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Support\LogRedactor;
use Throwable;

class DiagnosticEngine
{
    public function __construct(
        private readonly LogRedactor $redactor,
    ) {}

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
        }

        return $run;
    }
}

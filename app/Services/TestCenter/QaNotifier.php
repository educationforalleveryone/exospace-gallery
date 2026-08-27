<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use App\Models\QaTestRun;
use App\Services\OperationalAlertService;

/**
 * QA event notifications routed through the existing OperationalAlertService
 * (severity-split Slack webhooks you already operate) with its native
 * de-duplication TTLs so a red pipeline cannot spam a channel.
 *
 * Emitted events:
 *   1. Profile failure        — severity critical, dedup per profile+commit
 *   2. Release blocked flip   — critical, dedup global daily
 *   3. New regression found   — warning, dedup per identifier (first sighting)
 */
class QaNotifier
{
    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    public function runFailed(QaTestRun $run): void
    {
        $class = $run->failure_class;

        $title = "[QA] {$run->profile} FAILED on {$run->environment}";
        $body  = sprintf(
            "%d/%d green · %s%s\nCommit %s · branch %s\nRun #%d · see Control Center",
            $run->passed,
            max($run->total, 1),
            strtoupper((string) $run->displayStatus()),
            $class !== null ? " · classification: {$class}" : '',
            $run->git_commit !== null ? substr($run->git_commit, 0, 10) : 'unknown',
            $run->git_branch ?? '—',
            $run->id,
        );

        if ($class === 'infrastructure') {
            $title = "[QA] {$run->profile} could not complete — TEST INFRASTRUCTURE problem";
            $body .= "\nEnvironment broke, NOT the product. Fix runner/database/disk first.";
        }

        $this->alerts->alert(
            title: $title,
            message: $body,
            severity: $class === 'infrastructure' ? 'warning' : 'critical',
            dedupKey: 'qa_run_failed:'.$run->profile.':'.substr((string) $run->git_commit, 0, 10),
            escalate: false,
        );
    }

    public function releaseBlocked(string $verdictHash, array $summary): void
    {
        $reasonList = implode("\n", array_map(static fn ($r) => " • {$r}", array_slice($summary['reasons'], 0, 8)));

        $this->alerts->alert(
            title: '[Release] NOT READY TO SHIP',
            message: "Blocking gates failed:\n{$reasonList}\n\nOpen Control Center → Release Readiness for the full board.",
            severity: 'critical',
            dedupKey: 'qa_release_blocked:'.$verdictHash,
            escalate: false,
        );
    }

    public function newRegression(string $profile, string $identifier): void
    {
        $this->alerts->alert(
            title: '[QA] New regression appeared',
            message: "{$identifier} failed for the FIRST time in {$profile} history.\nThis is likely introduced by the current commit.",
            severity: 'warning',
            dedupKey: 'qa_new_regression:'.$identifier,
            escalate: false,
        );
    }
}

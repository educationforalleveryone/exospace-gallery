<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

final class DiagnosticResult
{
    public const STATUSES = ['healthy', 'degraded', 'failed', 'inconclusive'];

    public const FINDING_STATUSES = ['pass', 'warn', 'fail', 'skip'];

    private function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly array $findings,
        public readonly string $interpretation,
        public readonly array $nextSteps,
    ) {}

    public static function fromFindings(string $summary, array $findings, string $interpretation, array $nextSteps = []): self
    {
        $statuses = [];
        foreach ($findings as $finding) {
            $statuses[] = is_array($finding) ? (string) ($finding['status'] ?? 'skip') : 'skip';
        }

        $status = in_array('fail', $statuses, true)
            ? 'failed'
            : (in_array('warn', $statuses, true) ? 'degraded' : 'healthy');

        return new self($status, $summary, self::normalizeFindings($findings), $interpretation, self::normalizeSteps($nextSteps));
    }

    public static function inconclusive(string $summary, string $interpretation, array $nextSteps = []): self
    {
        return new self('inconclusive', $summary, [], $interpretation, self::normalizeSteps($nextSteps));
    }

    private static function normalizeFindings(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $status = in_array($finding['status'] ?? '', self::FINDING_STATUSES, true)
                ? (string) $finding['status']
                : 'skip';
            $out[] = [
                'label' => mb_substr((string) ($finding['label'] ?? 'Check'), 0, 200),
                'status' => $status,
                'detail' => mb_substr((string) ($finding['detail'] ?? ''), 0, 1000),
            ];
        }

        return $out;
    }

    private static function normalizeSteps(array $nextSteps): array
    {
        $steps = [];
        foreach ($nextSteps as $step) {
            if (is_string($step) && trim($step) !== '') {
                $steps[] = mb_substr(trim($step), 0, 500);
            }
        }

        return array_slice(array_values($steps), 0, 8);
    }
}

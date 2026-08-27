<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

/**
 * OpsCenter — DiagnosticResult (Iteration 3).
 *
 * The immutable value object every diagnostic runner returns. A runner never
 * throws and never persists anything — it performs its (read-only) checks and
 * hands back this DTO; the DiagnosticEngine owns persistence, redaction,
 * auditing and timing.
 *
 * Status semantics (rendered verbatim by the UI):
 *   healthy      — every check passed; nothing needs attention.
 *   degraded     — checks completed, at least one warning (or a threshold was
 *                  crossed). Investigate when convenient.
 *   failed       — at least one check failed. This is the "broken" answer.
 *   inconclusive — the diagnostic could not determine an answer (missing
 *                  context, information unavailable on this driver, wrong
 *                  application scope). NEVER a crash — an honest "I can't
 *                  tell from here", with an explanation.
 *
 * Findings are the individual checks:
 *   ['label' => 'Connection to database host', 'status' => 'pass|warn|fail|skip', 'detail' => '...']
 * 'skip' means "not applicable / information unavailable" and never worsens
 * the overall status — pretending to know is worse than admitting we don't.
 *
 * "Interpretation" is the plain-language paragraph the brief demands: not a
 * raw error dump, but "what this means for the operation".
 */
final class DiagnosticResult
{
    public const STATUSES = ['healthy', 'degraded', 'failed', 'inconclusive'];

    public const FINDING_STATUSES = ['pass', 'warn', 'fail', 'skip'];

    /**
     * @param  string  $status  healthy|degraded|failed|inconclusive
     * @param  string  $summary  One-line headline ("Database reachable — 4ms round-trip").
     * @param  array<int, array{label: string, status: string, detail: string}>  $findings
     * @param  string  $interpretation  Plain-language "what this means".
     * @param  array<int, string>  $nextSteps  Recommended follow-ups (strings; a step that
     *                                         exactly matches a registry diagnostic id renders
     *                                         as a one-click Run button).
     */
    private function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly array $findings,
        public readonly string $interpretation,
        public readonly array $nextSteps,
    ) {}

    /**
     * Build a result from findings, deriving the overall status:
     * any fail → failed; any warn → degraded; otherwise healthy.
     */
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

    /**
     * The diagnostic could not answer (wrong scope, information unavailable,
     * external API unreachable). An honest "cannot determine" — never a crash.
     */
    public static function inconclusive(string $summary, string $interpretation, array $nextSteps = []): self
    {
        return new self('inconclusive', $summary, [], $interpretation, self::normalizeSteps($nextSteps));
    }

    /**
     * Sanitize findings into the canonical shape (engine redacts afterwards;
     * this only guarantees structure + clamps runaway strings).
     */
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

    /**
     * @param  array<int, string>  $nextSteps
     * @return array<int, string>
     */
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

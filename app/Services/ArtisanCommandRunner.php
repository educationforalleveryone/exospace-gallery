<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;

/**
 * ITERATION 7 — invoke an artisan command in-process and report
 * its exit code. Extracted as a tiny collaborator so the
 * exospace:backup wrapper command (which wraps spatie's backup:run /
 * backup:clean) is unit-testable: tests swap this binding for a fake
 * that records the calls and returns a preset exit code, instead of
 * running a real spatie backup (which needs mysqldump + writable disk
 * + zip — out of scope for a unit test and a CI sandbox).
 *
 * The contract: __invoke($command, $parameters) returns the int exit
 * code. 0 = success; nonzero = failure. Same semantics as Artisan::call().
 *
 * ITERATION 8: the runner now captures the underlying command's
 * stdout via Artisan::call()'s optional $outputBuffer parameter and
 * exposes it via lastOutput() so the wrapper can append the spatie
 * diagnostic to the Slack alert copy (audit-fix C-2 — the operator
 * paged at 01:00 previously had to log into the box and tail the
 * scheduler log to see WHY the backup failed; now the last ~300
 * chars of spatie's stdout ride along in the alert).
 *
 * The captured output is per-call; concurrent invocations on the
 * same instance would clobber (but the wrapper invokes once per
 * handle() call so this is safe in practice — the schedule's
 * withoutOverlapping mutex guarantees no concurrent runs of the
 * same backup type anyway).
 */
class ArtisanCommandRunner
{
    /** @var string The stdout captured by the most recent call. */
    private string $lastOutput = '';

    /**
     * Invoke the underlying Artisan command, capturing its stdout.
     *
     * @param  string  $command  The artisan command name (e.g. 'backup:run').
     * @param  array<string, mixed>  $parameters  Command arguments/options.
     * @return int  The exit code (0 = success).
     */
    public function __invoke(string $command, array $parameters = []): int
    {
        // Artisan::call's third parameter is an output buffer (passed
        // by reference) that captures stdout from the command's
        // OutputStyle. Laravel fills it as an array of lines.
        $outputBuffer = [];
        $exit = Artisan::call($command, $parameters, $outputBuffer);
        $this->lastOutput = is_array($outputBuffer)
            ? implode("\n", $outputBuffer)
            : (string) $outputBuffer;
        return (int) $exit;
    }

    /**
     * The stdout captured by the most recent __invoke call.
     *
     * @return string  Empty string if no call has been made or the
     *                 underlying command produced no output.
     */
    public function lastOutput(): string
    {
        return $this->lastOutput;
    }
}

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
 */
class ArtisanCommandRunner
{
    public function __invoke(string $command, array $parameters = []): int
    {
        return (int) Artisan::call($command, $parameters);
    }
}

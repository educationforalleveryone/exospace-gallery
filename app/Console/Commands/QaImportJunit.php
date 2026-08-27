<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TestCenter\JunitParser;
use App\Services\TestCenter\RunRecorder;
use Illuminate\Console\Command;

/**
 * qa:import — ingest a JUnit artifact produced elsewhere (a CI runner, a
 * teammate's machine, the GitHub Actions workflow shipped in this repo)
 * into structured run history.
 *
 * Used by:
 *   - CI post-step (qa:import junit.xml --profile=pre_release --env=ci ...)
 *   - Control Center ingest API payload handling
 *   - Manual backfill: "I have junit from yesterday's failed release check"
 */
class QaImportJunit extends Command
{
    protected $signature = 'qa:import
                            {artifact : Path or "-" for stdin, to a JUnit XML file}
                            {--profile= : Profile key the artifact belongs to}
                            {--env=ci : Environment it ran against}
                            {--branch=} {--commit=} {--tag=}
                            {--ci-url= : GitHub Actions run URL}
                            {--trigger=ci : manual|ci|api|schedule}
                            {--duration-ms=}';

    protected $description = 'Import an existing JUnit XML test result into Control Center history';

    public function handle(RunRecorder $recorder): int
    {
        $profile = (string) $this->option('profile');

        if ($profile === '') {
            $this->error('--profile is required so history stays queryable per profile.');

            return self::FAILURE;
        }

        $input = (string) $this->argument('artifact');
        $path  = $input === '-' ? 'php://stdin' : $input;

        if ($input !== '-' && ! is_file($path)) {
            $this->error("Artifact not found: {$path}");

            return self::FAILURE;
        }

        try {
            $run = $recorder->record([
                'profile'     => $profile,
                'environment' => (string) $this->option('env'),
                'safety'      => config("test-profiles.profiles.{$profile}.safety", 'test-only'),
                'trigger'     => (string) $this->option('trigger') ?: 'ci',
                'runner'      => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'import:'.$this->option('trigger'),
                'git_branch'  => $this->option('branch'),
                'git_commit'  => $this->option('commit'),
                'git_tag'     => $this->option('tag'),
                'ci_run_url'  => $this->option('ci-url'),
                'meta'        => ['source' => 'manual-import'],
            ], $path, [
                'duration_ms' => $this->option('duration-ms') !== null ? (int) $this->option('duration-ms') : null,
            ]);
        } catch (\Throwable $e) {
            $this->components->error('IMPORT FAILED: '.$e->getMessage());
            $this->line('The artifact was NOT silently accepted — no phantom results were recorded.');

            return self::FAILURE;
        }

        $this->components->info("Imported as run #{$run->id}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Status',   strtoupper($run->status)],
                ['Tests',    number_format($run->total)],
                ['Passed',   number_format($run->passed)],
                ['Failed',   number_format($run->failed)],
                ['Errors',   number_format($run->errored)],
                ['Skipped',  number_format($run->skipped)],
                ['Branch',   $run->git_branch ?? '—'],
                ['Commit',   substr((string) $run->git_commit, 0, 10) ?: '—'],
            ]
        );

        return self::SUCCESS;
    }
}

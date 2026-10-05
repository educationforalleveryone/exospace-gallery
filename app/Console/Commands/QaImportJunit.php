<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TestCenter\RunRecorder;
use Illuminate\Console\Command;

class QaImportJunit extends Command
{
    protected $signature = 'qa:import
                            {artifact : Path or "-" for stdin, to a JUnit XML file}
                            {--profile= : Profile key the artifact belongs to}
                            {--environment=ci : Environment it ran against}
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

        if ($input === '-') {
            // The recorder/parser contract is file-based (is_file checks +
            // artifact archiving), so stdin input is spooled to a real file
            // first. The spooled copy then flows through the normal archive
            // path — stdin imports gain the same forensics as file imports.
            $spooled = $this->spoolStdin();

            if ($spooled === null) {
                $this->error('Stdin produced no JUnit XML — nothing to import.');

                return self::FAILURE;
            }

            $path = $spooled;
        } else {
            $path = $input;

            if (! is_file($path)) {
                $this->error("Artifact not found: {$path}");

                return self::FAILURE;
            }
        }

        try {
            $run = $recorder->record([
                'profile' => $profile,
                'environment' => (string) $this->option('environment'),
                'safety' => config("test-profiles.profiles.{$profile}.safety", 'test-only'),
                'trigger' => (string) $this->option('trigger') ?: 'ci',
                'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'import:'.$this->option('trigger'),
                'git_branch' => $this->option('branch'),
                'git_commit' => $this->option('commit'),
                'git_tag' => $this->option('tag'),
                'ci_run_url' => $this->option('ci-url'),
                'meta' => ['source' => 'manual-import'],
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

    private function spoolStdin(): ?string
    {
        $stream = fopen('php://stdin', 'rb');

        if ($stream === false) {
            return null;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $dir = storage_path('framework/qa');
        @mkdir($dir, 0775, true);

        $path = $dir.'/import-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)).'.xml';

        return file_put_contents($path, $contents) !== false ? $path : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class QaImportJunitTest extends TestCase
{
    /**
     * The stdin path (`qa:import -`) is exercised through a REAL subprocess:
     * the command consumes php://stdin, which cannot be piped from inside the
     * phpunit process. Each test provisions its own file-backed sqlite store,
     * migrates it once, then runs artisan against it and asserts on the rows.
     */
    private string $dbPath;

    private array $artifactSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/qa-import-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $this->dbPath = $dir.'/qa.sqlite';

        $migrate = $this->runArtisan(['migrate', '--force']);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput().$migrate->getOutput());

        $this->artifactSnapshot = glob(storage_path('app/private/control-center/*/*.xml')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach ((glob(storage_path('app/private/control-center/*/*.xml')) ?: []) as $file) {
            if (! in_array($file, $this->artifactSnapshot, true)) {
                @unlink($file);
            }
        }

        if (isset($this->dbPath) && $this->dbPath !== '') {
            @unlink($this->dbPath);
            @rmdir(dirname($this->dbPath));
        }

        parent::tearDown();
    }

    public function test_stdin_artifact_is_parsed_recorded_and_archived(): void
    {
        $junit = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
            <testsuite name="Demo" tests="2" assertions="3" failures="1" errors="0" skipped="0" time="0.5">
                <testcase name="test_one" classname="Tests\Feature\DemoTest" time="0.2"/>
                <testcase name="test_two" classname="Tests\Feature\DemoTest" time="0.3">
                    <failure type="AssertionFailed">boom</failure>
                </testcase>
            </testsuite>
        </testsuites>
        XML;

        $process = $this->runArtisan([
            'qa:import', '-', '--profile=quick_check', '--environment=ci',
            '--commit='.str_repeat('a', 40), '--branch=feature/stdin',
        ], $junit);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Imported as run #', $process->getOutput());

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $run = $pdo->query('SELECT profile, environment, status, total, passed, failed, git_commit, meta FROM qa_test_runs')->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('quick_check', $run['profile']);
        $this->assertSame('ci', $run['environment']);
        $this->assertSame('failed', $run['status'], '1 of 2 cases failed — run must not read as passed');
        $this->assertSame(2, (int) $run['total']);
        $this->assertSame(1, (int) $run['passed']);
        $this->assertSame(1, (int) $run['failed']);
        $this->assertSame(str_repeat('a', 40), $run['git_commit']);

        // The spooled stdin artifact must flow through the normal archive path.
        $meta = json_decode((string) $run['meta'], true);
        $this->assertNotEmpty($meta['artifact_path']);
        $this->assertFileExists(storage_path('app/private/'.$meta['artifact_path']));

        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM qa_test_case_results')->fetchColumn());
    }

    public function test_malformed_stdin_artifact_is_rejected_without_phantom_records(): void
    {
        $process = $this->runArtisan(['qa:import', '-', '--profile=quick_check'], 'definitely not xml');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('IMPORT FAILED', $process->getOutput());

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM qa_test_runs')->fetchColumn());
    }

    public function test_empty_stdin_is_refused_before_any_record_is_created(): void
    {
        $process = $this->runArtisan(['qa:import', '-', '--profile=quick_check'], '');

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Stdin produced no JUnit XML', $process->getOutput());

        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM qa_test_runs')->fetchColumn());
    }

    private function runArtisan(array $args, ?string $input = null): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, base_path('artisan')], $args),
            base_path(),
            array_merge(getenv() ?: [], [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->dbPath,
            ]),
            null,
            180,
        );

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        return $process;
    }
}

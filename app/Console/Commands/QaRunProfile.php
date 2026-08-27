<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QaTestRun;
use App\Services\TestCenter\EnvironmentSafety;
use App\Services\TestCenter\JunitParser;
use App\Services\TestCenter\RunRecorder;
use App\Services\TestCenter\TestProfileRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * qa:run — execute a Testing Control Center profile.
 *
 * The human-facing translation of "which php artisan test filter do I run?".
 * Everything dangerous (production targeting, incompatible concurrency)
 * is refused HERE so the operator cannot bypass safety by accident.
 *
 * Strategies:
 *   phpunit           → generated suite XML → vendor/bin/phpunit → JUnit artifact
 *   http-smoke        → delegates to qa:smoke (iteration 3 command; config ships now)
 *   in-process-checks → delegates to QaInProcessChecks service
 */
class QaRunProfile extends Command
{
    protected $signature = 'qa:run
                            {profile? : Profile key (see --list)}
                            {--list : Show available profiles and exit}
                            {--target=local : Target environment for safety policy (local|ci|staging|production)}
                            {--database= : Override profile DB preference (sqlite|mysql)}
                            {--coverage : Collect code coverage (requires PCOV/Xdebug, CI-only by default)}
                            {--no-record : Execute but do not persist a run record}
                            {--dry-run : Resolve what WOULD run and validate prerequisites without executing}';

    protected $description = 'Execute a Testing Control Center test profile (safe, structured, recorded)';

    public function handle(
        TestProfileRegistry $registry,
        EnvironmentSafety $safety,
        RunRecorder $recorder,
        JunitParser $parser,
    ): int {
        if ($this->option('list')) {
            return $this->renderProfileList($registry);
        }

        $key = (string) $this->argument('profile');

        if ($key === '') {
            $this->error('Provide a profile key or use --list.');
            $this->line('Available: '.implode(', ', array_keys($registry->profiles())));

            return self::FAILURE;
        }

        try {
            $profile = $registry->profile($key);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $targetEnv = (string) $this->option('target') ?: 'local';

        // ── SAFETY GATE ────────────────────────────────────────────────────
        $verdict = $safety->evaluate($key, $profile, $targetEnv);
        if (! $verdict['allowed']) {
            $run = $this->recordBlocked($recorder, $key, $profile, $targetEnv, $verdict['reason']);

            $this->components->error('EXECUTION REFUSED');
            $this->components->twoColumnDetail('Reason', (string) $verdict['reason']);
            if (! empty($verdict['remediation'])) {
                $this->components->twoColumnDetail('How to fix', (string) $verdict['remediation']);
            }
            $this->newLine();
            $this->info("Recorded as BLOCKED (run #{$run->id}) so history reflects the attempt honestly.");

            return self::INVALID;
        }
        unset($run);

        // ── STRATEGY DISPATCH ──────────────────────────────────────────────
        $strategy = $profile['strategy'] ?? 'phpunit';

        if ($strategy === 'http-smoke' || $strategy === 'in-process-checks') {
            $this->warn("Strategy [{$strategy}] is delivered with iteration 3. Config already registered this profile.");

            return self::SUCCESS;
        }

        // ── PREREQUISITES (fail-fast, never 400 meaningless failures) ─────
        $dbPreference = $this->option('database') ?: ($profile['database'] ?? 'sqlite');
        $prereq = $this->checkPrerequisites($dbPreference);
        if ($prereq !== null) {
            $run = $this->recordBlocked($recorder, $key, $profile, $targetEnv, $prereq['reason']);
            $this->components->error('TEST ENVIRONMENT NOT READY');
            $this->components->twoColumnDetail('Missing / failing', $prereq['reason']);
            $this->components->twoColumnDetail('How to fix', $prereq['fix']);
            foreach (($prereq['required'] ?? []) as $req) {
                $this->components->bulletList([$req]);
            }
            $this->line("Recorded as BLOCKED (run #{$run->id}).");

            return self::INVALID;
        }

        if ((bool) $this->option('dry-run')) {
            $paths = $registry->resolvePaths($key);
            $fileCount = collect($paths)->sum(fn ($p) => is_dir($p)
                ? count(glob(rtrim($p, '/').'/*Test.php')) + count(glob(rtrim($p, '/').'/**/*Test.php'))
                : 1);

            $this->components->info('DRY RUN — all prerequisites satisfied');
            $this->components->twoColumnDetail('Profile', $profile['label'] ?? $key);
            $this->components->twoColumnDetail('Strategy', $strategy);
            $this->components->twoColumnDetail('Database', $dbPreference);
            $this->components->twoColumnDetail('Resolved locations', implode("\n", $paths));
            $this->components->twoColumnDetail('Approx. files matched', (string) $fileCount);

            return self::SUCCESS;
        }

        $this->ensureRunnerRecordStore();

        // ── CONCURRENCY GUARD ──────────────────────────────────────────────
        $conflicts   = (array) ($profile['conflicts_with'] ?? []);
        $lockKey     = "qa:run:{$targetEnv}";
        $lockSeconds = (int) config('test-center.lock_seconds', 3600);

        $lock = Cache::lock($lockKey, $lockSeconds);

        if (! $lock->get()) {
            $this->components->warn("Another profile run holds the [{$lockKey}] lock (profiles may interfere). Waiting is not automatic — inspect running job via the dashboard or wait {$lockSeconds}s.");

            return self::INVALID;
        }

        $blockingKeys = [];
        foreach ($conflicts as $conflictKey) {
            $blockingKeys[] = "qa:lastrun:{$conflictKey}:{$targetEnv}";
        }

        // Note on conflict semantics: the lock prevents truly concurrent runs;
        // conflicts_with additionally warns when a conflicting profile ran very recently.
        if ($blockingKeys !== []) {
            foreach ($blockingKeys as $bk) {
                $recent = Cache::get($bk);
                if (is_string($recent)) {
                    $this->components->warn("Recent conflicting profile detected ({$bk}): {$recent}. Proceeding sequentially inside the exclusive lock.");
                }
            }
        }

        try {
            return $this->executePhpunitStrategy($registry, $recorder, $parser, $key, $profile, $targetEnv, $dbPreference, $lock);
        } finally {
            optional($lock)->release();
            Cache::put("qa:lastrun:{$key}:{$targetEnv}", now()->toIso8601String(), now()->addHours(24));
        }
    }

    /**
     * The RUNNER process records history into the application's default
     * connection. In CI / fresh checkouts that is a file-based SQLite store
     * which may not exist yet — provision it here so recording can never be
     * the reason a validated result gets lost.
     */
    private function ensureRunnerRecordStore(): void
    {
        $connection = \DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return; // production/staging MySQL already migrated by deploy pipeline
        }

        $database = (string) ($connection->getConfig('database') ?? '');

        if ($database === '' || $database === ':memory:') {
            return; // in-memory is fine (records die with the process honestly)
        }

        if (! file_exists($database)) {
            @mkdir(dirname($database), 0775, true);
            touch($database);
            $this->components->info("Provisioned runner record store: {$database}");
            \Artisan::call('migrate', ['--force' => true]);
        }
    }

    /* ---------------------------------------------------------------------
    |  PHPUnit strategy
    |-------------------------------------------------------------------- */

    private function executePhpunitStrategy(
        TestProfileRegistry $registry,
        RunRecorder $recorder,
        JunitParser $parser,
        string $key,
        array $profile,
        string $targetEnv,
        string $dbPreference,
        $lock,
    ): int {
        [$suiteXmlPath, $envOverrides] = $this->buildSuiteXmlAndEnv($registry, $key, $profile, $dbPreference);

        $artifactPath = storage_path('framework/qa/'.$key.'-'.now()->format('YmdHis').'-junit.xml');
        @mkdir(dirname($artifactPath), 0775, true);

        $phpunitBinary = config('test-center.phpunit_binary', 'vendor/bin/phpunit');

        if (! file_exists(base_path($phpunitBinary))) {
            $this->recordNotReady($recorder, $key, $profile, $targetEnv,
                "PHPUnit binary not found at {$phpunitBinary}",
                'composer install (dev dependencies must NOT be pruned for local runs)');

            $this->components->error("PHPUnit binary missing: base_path/{$phpunitBinary}");

            return self::INVALID;
        }

        $phpBinary = PHP_BINARY;
        $args = [
            $phpBinary,
            base_path($phpunitBinary),
            '--configuration', $suiteXmlPath,
            '--log-junit', $artifactPath,
            '--no-progress',
        ];

        if ((bool) $this->option('coverage')) {
            $args[] = '--coverage-text='.storage_path('framework/qa/'.$key.'-coverage.txt');
            $args[] = '--coverage-cobertura'; // future ingestion; requires pcov/xdebug
        }

        $this->components->info(($profile['icon'] ?? '🧪').' Running '.$key.' ('.($profile['label'] ?? '').')');
        $this->components->twoColumnDetail('Target env', $targetEnv);
        $this->components->twoColumnDetail('Database', $envOverrides['DB_CONNECTION'] ?? 'sqlite');
        $this->newLine();

        $startedAt      = now();
        $startTimestamp = microtime(true);

        $process = new Process($args, base_path(), $envOverrides, null,
            (float) config('test-center.timeout_seconds', 1800));
        // Subprocess contract: ALWAYS testing semantics for suite execution.
        // Belt-and-braces: critical stores are pinned HERE as well because
        // phpunit.xml `force="true"` alone proved insufficient against an
        // operator shell exporting e.g. CACHE_STORE=file (a shared file
        // store leaks RateLimiter hits ACROSS tests/processes producing
        // phantom 429s). Deterministic > clever.
        $process->setEnv(array_merge(getenv() ?: [], $_ENV ?? [], $envOverrides, [
            'APP_ENV'           => 'testing',
            'APP_KEY'           => env('APP_KEY') ?: ($_ENV['APP_KEY'] ?? ''),
            'CACHE_STORE'       => 'array',
            'SESSION_DRIVER'    => 'array',
            'QUEUE_CONNECTION'  => 'sync',
            'MAIL_MAILER'       => 'array',
            'DB_CONNECTION'     => $envOverrides['DB_CONNECTION'] ?? null,
            'DB_DATABASE'       => $envOverrides['DB_DATABASE'] ?? ':memory:',
        ]));

        // Stream output so long suites feel alive instead of hanging silently.
        $exitCode = $process->run(function (string $type, string $buffer): void {
            $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $buffer);
            echo $clean;
            flush();
        });

        $finishedAt     = now();
        $wallClockMs    = (int) round((microtime(true) - $startTimestamp) * 1000);

        if (! file_exists($artifactPath)) {
            $reason = 'Test runner produced no JUnit artifact — process probably timed out or crashed before executing tests.';
            $this->recordNotReady($recorder, $key, $profile, $targetEnv, $reason,
                'Re-run with fewer filters; check PHP fatal output above.');

            $this->components->error($reason);

            return $exitCode === 0 ? self::FAILURE : $exitCode;
        }

        $parsed = $parser->parseFile($artifactPath);
        $totals = $parsed['totals'];

        $status = match (true) {
            $totals['tests'] === 0                          => QaTestRun::STATUS_NOT_EXECUTED,
            ($totals['failures'] + $totals['errors']) === 0 => QaTestRun::STATUS_PASSED,
            default                                         => QaTestRun::STATUS_FAILED,
        };

        $metadata = [
            'profile'     => $key,
            'environment' => $targetEnv,
            'safety'      => $profile['safety'] ?? 'test-only',
            'trigger'     => 'manual',
            'runner'      => $this->runnerName(),
            'db_driver'   => $envOverrides['DB_CONNECTION'] ?? 'sqlite',
            'meta'        => ['suite_xml' => basename($suiteXmlPath), 'exit_code' => $exitCode],
        ];

        $run = $recorder->record($metadata, $artifactPath, [
            'status'       => $status,
            'started_at'   => $startedAt,
            'finished_at'  => $finishedAt,
            'duration_ms'  => $wallClockMs,
        ]);

        $this->renderSummary($parser, $key, $run, $status);

        return $status === QaTestRun::STATUS_PASSED ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Generate a temp PHPUnit <testsuites> XML derived from the project's own
     * phpunit.xml (keeps its <php> env block & source map) while replacing
     * only the suite list with paths resolved from the profile registry.
     *
     * @return array{0:string,1:array<string,string>} path + env overrides
     */
    private function buildSuiteXmlAndEnv(
        TestProfileRegistry $registry,
        string $key,
        array $profile,
        string $dbPreference,
    ): array {
        $baseXmlPath = base_path('phpunit.xml');

        if (! file_exists($baseXmlPath)) {
            throw new RuntimeException('phpunit.xml not found in project root.');
        }

        $dom = new \DOMDocument();
        $dom->load($baseXmlPath);

        // PHPUnit resolves relative paths (bootstrap, xsd) against the CONFIG
        // FILE'S directory. Our generated copy lives in storage/framework/qa,
        // so pin every path attribute to absolute project locations.
        $root = $dom->documentElement;
        if ($root !== null && $root->hasAttribute('bootstrap')) {
            $root->setAttribute('bootstrap', base_path($root->getAttribute('bootstrap')));
        }

        // Force critical <env> entries so an operator's exported shell vars
        // (APP_ENV=production, stray DB creds…) can NEVER leak into suites.
        // Without force=true phpunit.xml yields to pre-existing env, which is
        // exactly how a local run quietly turned on production CSRF/session
        // middleware and produced 400 bogus 419 failures. (Hardening fix)
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//php/env') as $envNode) {
            $name = $envNode->getAttribute('name');
            if (in_array($name, ['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'MAIL_MAILER'], true)) {
                $envNode->setAttribute('force', 'true');
            }
        }

        $suites  = $xpath->query('//testsuites')->item(0);

        while ($suites->firstChild) {
            $suites->removeChild($suites->firstChild);
        }

        $passes = [[
            'name'         => $key,
            'paths'        => $registry->resolvePaths($key),
            'env_override' => [],
        ]];

        $extraPasses = [];
        foreach (($profile['extra_passes'] ?? []) as $i => $passConfig) {
            $labelSuffix = $passConfig['__label_suffix'] ?? ('#'.($i + 2));
            $env = array_diff_key($passConfig, ['__label_suffix' => true]);
            $extraPasses[] = [
                'name'         => $key.$labelSuffix,
                'paths'        => $registry->resolvePaths($key),
                'env_override' => $env,
            ];
        }

        $chosenPasses = $extraPasses === [] ? $passes : array_merge($passes, $extraPasses);

        foreach ($chosenPasses as $pass) {
            $suiteEl = $dom->createElement('testsuite');
            $suiteEl->setAttribute('name', $pass['name']);

            foreach ($pass['paths'] as $path) {
                // Absolute paths are mandatory: PHPUnit resolves every entry
                // in a config file relative to THAT FILE's directory, not cwd.
                $isDirectory = is_dir($path);
                $node = $dom->createElement($isDirectory ? 'directory' : 'file');
                $node->nodeValue = $path;
                if ($isDirectory) {
                    $node->setAttribute('suffix', 'Test.php');
                }
                $suiteEl->appendChild($node);
            }

            $suites->appendChild($suiteEl);
        }

        $outPath = storage_path('framework/qa/suite-'.$key.'-'.bin2hex(random_bytes(4)).'.xml');
        @mkdir(dirname($outPath), 0775, true);
        $dom->save($outPath);

        // First pass drives which DB engine runs: quick=sqlite memory etc.
        $envOverrideFirst = $chosenPasses[0]['env_override'];
        if ($dbPreference === 'mysql' || $dbPreference === 'mysql-required') {
            $cfg = config('test-center.mysql_test');
            $envOverrideFirst += [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST'       => (string) $cfg['host'],
                'DB_PORT'       => (string) $cfg['port'],
                'DB_DATABASE'   => (string) $cfg['database'],
                'DB_USERNAME'   => (string) $cfg['username'],
                'DB_PASSWORD'   => (string) $cfg['password'],
            ];
        } elseif ($dbPreference === 'sqlite') {
            $envOverrideFirst += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'];
        }

        return [$outPath, $envOverrideFirst];
    }

    /* ---------------------------------------------------------------------
    |  Prerequisite checks
    |-------------------------------------------------------------------- */

    /**
     * @return array{reason:string, fix:string, required?:array}|null null = ready
     */
    private function checkPrerequisites(string $dbPreference): ?array
    {
        // A poisoned .env (e.g. production SESSION_DOMAIN / SECURE cookie /
        // redis drivers leaking into a testing boot) causes *auth-flow* test
        // failures that look like product bugs. Catch it early and loudly.
        $poisonous = [
            'SESSION_DOMAIN'      => '/^(?!$)/',
            'SESSION_SECURE_COOKIE'=> '/^(true|1)$/i',
        ];

        foreach ($poisonous as $var => $badPattern) {
            $value = (string) getenv($var);
            if ($value !== '' && preg_match($badPattern, $value) && ! file_exists(base_path('.env.testing'))) {
                return [
                    'reason'   => "Suspicious {$var}={$value} present from your .env — this poisons session-based auth tests.",
                    'fix'      => 'Create an empty-ish .env.testing, or remove these overrides while running suites (CI already isolates via `cp .env.example .env`).',
                    'required' => ['.env.testing OR clean shell'],
                ];
            }
        }

        if ($dbPreference === 'mysql-required' || $dbPreference === 'mysql') {
            if (! extension_loaded('pdo_mysql')) {
                return [
                    'reason' => 'PHP extension pdo_mysql is missing — MySQL profiles cannot run.',
                    'fix'    => 'Install/enable pdo_mysql, or run this profile with sqlite (`--database=sqlite`) accepting reduced fidelity.',
                ];
            }

            $cfg = config('test-center.mysql_test');
            if (empty($cfg['host'])) {
                if ($dbPreference === 'mysql-required') {
                    return [
                        'reason'   => 'TEST_MYSQL_HOST is not configured.',
                        'fix'      => 'Set TEST_MYSQL_* variables in .env (or your runner secrets) pointing at an ephemeral MySQL 8 test database.',
                        'required' => ['TEST_MYSQL_HOST', 'TEST_MYSQL_PORT', 'TEST_MYSQL_DATABASE', 'TEST_MYSQL_USERNAME', 'TEST_MYSQL_PASSWORD'],
                    ];
                }

                // mysql-preferred without server available → degrade politely
                $this->components->warn('MySQL preferred but TEST_MYSQL_HOST unset — falling back to SQLite (:memory:). Engine-specific behaviour is NOT covered by this run.');

                return null;
            }

            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']);
                new \PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [\PDO::ATTR_TIMEOUT => 5]);
            } catch (\Throwable $e) {
                return [
                    'reason' => 'Cannot connect to MySQL test database: '.preg_replace('/(password[^ ]*)/i', '[redacted]', $e->getMessage()),
                    'fix'    => 'Start the test MySQL container/service, verify credentials, then retry. This failure would otherwise have surfaced as hundreds of connection errors.',
                ];
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
    |  Presentation helpers
    |-------------------------------------------------------------------- */

    private function renderSummary(JunitParser $parser, string $key, QaTestRun $run, string $status): void
    {
        $this->newLine(2);

        $rows = [
            ['Profile',       $key],
            ['Status',        strtoupper($status)],
            ['Tests',         number_format($run->total)],
            ['Passed',        number_format($run->passed)],
            ['Failed',        number_format($run->failed)],
            ['Errors',        number_format($run->errored)],
            ['Skipped',       number_format($run->skipped)],
            ['Duration',      gmdate('i:s', (int) ($run->duration_ms / 1000)).' min'],
            ['Failure class', $run->failure_class ?? '—'],
            ['Recorded',      "run #{$run->id} · commit ".substr((string) $run->git_commit, 0, 7)],
        ];

        $this->table(['Metric', 'Value'], $rows);

        if ($status === QaTestRun::STATUS_FAILED) {
            $topFailures = $run->cases()->whereIn('status', ['failed', 'error'])->limit(15)->get(['test_identifier', 'message']);
            $this->components->error('FAILING CASES');
            foreach ($topFailures as $case) {
                $firstLine = trim(explode("\n", (string) $case->message)[0]);
                $this->line(sprintf(" • %s\n     %s", $case->test_identifier, mb_substr($firstLine, 0, 160)));
            }
            if ($run->failure_class === 'infrastructure') {
                $this->components->warn('Classification: INFRASTRUCTURE failures dominate — do NOT treat this as application breakage. Fix the environment first.');
            }
        } elseif ($status === QaTestRun::STATUS_NOT_EXECUTED) {
            $this->components->warn('NOT EXECUTED — zero tests were executed by the runner. Review raw output above.');
        } else {
            $this->components->success("✅ {$key}: {$run->passed} passed, {$run->skipped} skipped.");
        }
    }

    private function renderProfileList(TestProfileRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->summarizeForList() as $key => $meta) {
            $rows[] = [
                $key,
                ($meta['icon'] ?? '').' '.$meta['label'],
                $meta['safety'],
                $meta['strategy'],
                (string) $meta['estimated_minutes'],
                $meta['description'],
            ];
        }

        $this->table(['Key', 'Label', 'Safety', 'Strategy', '~min', 'Description'], $rows);
        $this->line('');
        $this->line('Run one:        php artisan qa:run pre_release');
        $this->line('Different env:  php artisan qa:run smoke --target=production');

        return self::SUCCESS;
    }

    private function runnerName(): string
    {
        if (getenv('GITHUB_ACTIONS') === 'true') {
            return 'github-actions';
        }
        $host = gethostname();

        return 'local:'.$host;
    }

    private function recordBlocked(RunRecorder $recorder, string $key, array $profile, string $env, ?string $reason): QaTestRun
    {
        return $recorder->record([
            'profile'        => $key,
            'environment'    => $env,
            'safety'         => $profile['safety'] ?? 'test-only',
            'trigger'        => 'manual',
            'blocked_reason' => $reason,
        ], null, ['status' => QaTestRun::STATUS_BLOCKED]);
    }

    private function recordNotReady(RunRecorder $recorder, string $key, array $profile, string $env, string $reason, string $fix): QaTestRun
    {
        return $recorder->record([
            'profile'        => $key,
            'environment'    => $env,
            'safety'         => $profile['safety'] ?? 'test-only',
            'trigger'        => 'manual',
            'runner'         => $this->runnerName(),
            'blocked_reason' => "{$reason} — fix: {$fix}",
        ], null, ['status' => QaTestRun::STATUS_NOT_EXECUTED]);
    }
}

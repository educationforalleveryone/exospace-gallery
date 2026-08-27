<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * qa:doctor — preflight diagnostics for the TESTING environment.
 *
 * Runs the checks a human would otherwise forget, BEFORE a big suite
 * produces 400 meaningless failures:
 *   - PHP version & required extensions (composer.json declared + test needs)
 *   - Composer dev dependencies present (vendor/bin/phpunit)
 *   - Frontend build artifacts (Vite manifest) needed by page-render tests
 *   - .env poisoning traps (production SESSION_DOMAIN etc.)
 *   - stale compiled views that shadow templates
 *   - APP_KEY present and valid length
 *   - SQLite driver availability; MySQL reachability when configured
 *   - Redis extension presence (only as warning when queue/cache use it)
 *   - storage writability + free disk space
 *   - git metadata availability for run attribution
 *
 * Exit codes: 0 = healthy/warnings only · 1 = blocking issues found.
 */
class QaDoctor extends Command
{
    protected $signature = 'qa:doctor
                            {--profile= : Validate for a specific profile (applies its DB requirement)}
                            {--format=text : text|json}';

    protected $description = 'Check whether THIS machine is ready to execute test suites (before wasting a run)';

    /** @var list<array{id:string,label:string,level:string,detail:?string,fix:?string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->runAllChecks();

        $blocking = array_filter($this->findings, fn ($f) => $f['level'] === 'critical');
        $warnings = array_filter($this->findings, fn ($f) => $f['level'] === 'warning');
        $passing  = array_filter($this->findings, fn ($f) => $f['level'] === 'ok');

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'ready'     => $blocking === [],
                'summary'   => ['pass' => count($passing), 'warnings' => count($warnings), 'blocking' => count($blocking)],
                'checks'    => array_values($this->findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $blocking === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('🩺 Testing Environment Doctor');
        $this->newLine();

        foreach ($this->findings as $finding) {
            match ($finding['level']) {
                'ok'       => $this->components->twoColumnDetail('✔ '.$finding['label'], (string) $finding['detail']),
                'warning'  => $this->renderProblem('⚠', $finding),
                default    => $this->renderProblem('✖', $finding),
            };
        }

        $this->newLine();
        if ($blocking !== []) {
            $this->components->error('TEST ENVIRONMENT NOT READY — '.count($blocking).' blocking issue(s). Fix them before running suites; otherwise expect cascading false failures.');
            foreach ($blocking as $b) {
                if (! empty($b['fix'])) {
                    $this->line('   fix → '.$b['fix']);
                }
            }

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $warningsCount = count($warnings);
            $this->components->warn("READY WITH WARNINGS ({$warningsCount} warnings, ".count($passing).' checks passed).');

            return self::SUCCESS;
        }

        $this->components->success('READY — '.count($passing).' checks passed. This environment can execute test suites honestly.');

        return self::SUCCESS;
    }

    /* --------------------------------------------------------------------- */

    private function renderProblem(string $icon, array $finding): void
    {
        $this->components->twoColumnDetail($icon.' '.$finding['label'], (string) $finding['detail']);
        if (! empty($finding['fix']) && $this->getOutput()->isVerbose()) {
            $this->line('      fix → '.$finding['fix']);
        }
    }

    private function finding(string $id, string $label, string $level, ?string $detail = null, ?string $fix = null): void
    {
        $this->findings[] = compact('id', 'label', 'level', 'detail', 'fix');
    }

    private function runAllChecks(): void
    {
        // ── PHP runtime ────────────────────────────────────────────────────
        $composerJson = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $requiredPhp  = $composerJson['require']['php'] ?? '^8.2';
        $phpOk        = version_compare(PHP_VERSION, '8.2.0', '>=');

        $this->finding('php_version', 'PHP version', $phpOk ? 'ok' : 'critical', PHP_VERSION,
            $phpOk ? null : "Composer requires {$requiredPhp}; upgrade PHP.");

        $declaredExtensions = array_map(
            fn ($ext) => str_starts_with($ext, 'ext-') ? substr($ext, 4) : $ext,
            array_keys(array_filter($composerJson['require'] ?? [], fn ($_,$k) => str_starts_with($k, 'ext-'), ARRAY_FILTER_USE_BOTH))
        );
        $testNeeded = ['pdo_sqlite', 'sqlite3', 'mbstring', 'dom', 'tokenizer', 'xml'];
        foreach (array_unique(array_merge($declaredExtensions, $testNeeded)) as $ext) {
            $loaded = extension_loaded($ext);
            $neededNow = in_array($ext, $testNeeded, true);
            $this->finding(
                "ext_{$ext}",
                "PHP ext: {$ext}",
                $loaded || ! $neededNow ? 'ok' : 'critical',
                $loaded ? 'loaded' : 'missing',
                $loaded ? null : "enable extension {$ext} (see php.ini of your CLI binary)",
            );
        }

        if (extension_loaded('pdo_mysql')) {
            $this->finding('ext_pdo_mysql_state', 'MySQL fidelity runs', 'ok', 'pdo_mysql available');
        } else {
            $this->finding('ext_pdo_mysql_state', 'MySQL fidelity runs', 'warning', 'pdo_mysql missing — profiles requiring MySQL will be BLOCKED (honest refusal), sqlite only.',
                'Enable pdo_mysql or accept sqlite-only quick checks.');
        }

        // ── Vendor / PHPUnit ──────────────────────────────────────────────
        $phpunit = base_path('vendor/bin/phpunit');
        $this->finding('phpunit_binary', 'PHPUnit installed', is_file($phpunit) ? 'ok' : 'critical',
            is_file($phpunit) ? basename($phpunit) : 'vendor/bin/phpunit missing',
            is_file($phpunit) ? null : 'run `composer install` with dev dependencies');

        // ── APP_KEY sanity ────────────────────────────────────────────────
        $appKey = (string) env('APP_KEY');
        $keyValid = str_starts_with($appKey, 'base64:') && in_array(strlen((string) base64_decode(substr($appKey, 7), true)), [16, 32], true);
        $this->finding('app_key', 'APP_KEY present/valid', $keyValid ? 'ok' : 'critical',
            $keyValid ? 'aes-'.(strlen((string) base64_decode(substr($appKey, 7), true)) === 32 ? '256' : '128') : 'missing or wrong length',
            $keyValid ? null : '`php artisan key:generate` in the test environment');

        // ── Vite manifest (page-render tests 500 without it) ──────────────
        $manifest = public_path('build/manifest.json');
        $hasManifest = file_exists($manifest);
        $this->finding('vite_manifest', 'Frontend build (Vite manifest)', $hasManifest ? 'ok' : 'critical',
            $hasManifest ? 'present' : 'public/build/manifest.json missing',
            $hasManifest ? null : 'npm ci && npm run build (page-rendering tests 500 without assets)');

        // ── .env poisoning trap ───────────────────────────────────────────
        $poisons = [];
        if ((string) getenv('SESSION_DOMAIN') !== '') {
            $poisons[] = 'SESSION_DOMAIN='.(string) getenv('SESSION_DOMAIN');
        }
        if (filter_var(getenv('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOL)) {
            $poisons[] = 'SESSION_SECURE_COOKIE=true';
        }
        if ((string) config('session.driver') === 'redis' && app()->environment() !== 'testing') {
            $poisons[] = 'SESSION_DRIVER=redis (not isolated)';
        }
        $envTestingExists = file_exists(base_path('.env.testing'));
        $this->finding('env_poisoning', 'Environment isolation (.env traps)',
            ($poisons === [] || $envTestingExists) ? 'ok' : 'critical',
            $poisons === [] ? 'clean' : implode('; ', $poisonSummary = $poisons),
            ($poisons === [] || $envTestingExists) ? null : 'Remove these overrides for local suite runs (CI does `cp .env.example .env`), or create .env.testing.');

        // ── Stale compiled views ─────────────────────────────────────────
        $staleViews = glob(storage_path('framework/views/*.php')) ?: [];
        $this->finding('stale_views', 'Stale compiled views',
            $staleViews === [] ? 'ok' : 'warning',
            $staleViews === [] ? 'none' : count($staleViews).' compiled blade files present',
            $staleViews === [] ? null : 'php artisan view:clear (stale compiled output has historically masked template bugs)');

        // ── Storage writability ──────────────────────────────────────────
        $probe = storage_path('framework/.qa-doctor-probe');
        $writable = @file_put_contents($probe, 'ok') !== false;
        if ($writable) {
            @unlink($probe);
        }
        $this->finding('storage_writable', 'storage/framework writable', $writable ? 'ok' : 'critical',
            $writable ? 'writable' : 'read-only',
            $writable ? null : 'chmod -R u+rwX storage && chown user storage');

        // Free disk space (light threshold 500MB)
        $freeBytes = (float) (@disk_free_space(storage_path()) ?: 0);
        $this->finding('disk_free', 'Disk space', $freeBytes > 500 * 1024 * 1024 ? 'ok' : 'warning',
            number_format($freeBytes / 1024 / 1024).' MB free',
            $freeBytes > 500 * 1024 * 1024 ? null : 'Free space — RefreshDatabase churn fills disks fast.');

        // ── Git attribution ──────────────────────────────────────────────
        $hasGit = is_dir(base_path('.git'));
        $this->finding('git_meta', 'Git metadata for run attribution', $hasGit ? 'ok' : 'warning',
            $hasGit ? 'repository found' : 'no .git directory',
            $hasGit ? null : 'Runs will record unknown commit unless --commit passed on import.');

        // ── Profile-specific DB reachability ─────────────────────────────
        $requestedProfile = (string) $this->option('profile');
        if ($requestedProfile !== '' && config()->has("test-profiles.profiles.{$requestedProfile}")) {
            $dbReq = config("test-profiles.profiles.{$requestedProfile}.database");
            if (in_array($dbReq, ['mysql', 'mysql-required'], true)) {
                $cfg = config('test-center.mysql_test');
                if (empty($cfg['host'])) {
                    $blocking = $dbReq === 'mysql-required';
                    $this->finding('mysql_config', 'TEST_MYSQL_* configured', $blocking ? 'critical' : 'warning',
                        'TEST_MYSQL_HOST empty',
                        'Set TEST_MYSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD for full-fidelity runs.');
                } else {
                    try {
                        new \PDO(
                            sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
                            (string) $cfg['username'],
                            (string) $cfg['password'],
                            [\PDO::ATTR_TIMEOUT => 5]
                        );
                        $this->finding('mysql_connect', 'MySQL test DB reachable', 'ok', "{$cfg['host']}:{$cfg['port']}/{$cfg['database']}");
                    } catch (\Throwable $e) {
                        $blocking = $dbReq === 'mysql-required';
                        $this->finding('mysql_connect', 'MySQL test DB reachable', $blocking ? 'critical' : 'warning',
                            preg_replace('/password[^ ]+/i', '[redacted]', $e->getMessage()),
                            'Start ephemeral MySQL (docker compose / GH service container) and re-check.');
                    }
                }
            }
        } elseif ($requestedProfile !== '') {
            $this->finding('unknown_profile', "Profile [{$requestedProfile}]", 'critical', 'not defined in config/test-profiles.php',
                'Add the profile or pick an existing one (`qa:run --list`).');
        }

        // silence static analysis on potential undefined var in ternary above
        unset($poisonSummary);
    }
}

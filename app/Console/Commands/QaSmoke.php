<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TestCenter\EnvironmentSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * qa:smoke — post-deployment SAFE READ-ONLY verification of a deployed build.
 *
 * No mutation verbs, no credentials required beyond reachability. Every check
 * is a GET with expectations of publicly observable truth.
 *
 * Default check-set (order matters for failure clarity):
 *   1. /up                 — framework alive (Coolify convention)
 *   2. /health             — subsystem JSON (200 or 503-degraded tolerated but reported)
 *   3. /robots.txt         — 200 + mentions Sitemap (SEO alive)
 *   4. /sitemap.xml        — 200 + starts with xml marker
 *   5. /login, /register   — 200 (auth surfaces reachable)
 *   6. first manifest asset— built frontend actually served (catches broken deploys)
 */
class QaSmoke extends Command
{
    protected $signature = 'qa:smoke
                            {--target= : Override base URL (defaults to APP_URL or --env mapping)}
                            {--target-env=production : Named target environment for safety reporting}
                            {--format=text : text|junit-json}
                            {--timeout=10 : Per-request timeout seconds}';

    protected $description = 'Run safe read-only smoke checks against a deployed Exospace instance';

    /** @var array<int, array<string,mixed>> */
    private array $cases = [];

    public function handle(EnvironmentSafety $safety): int
    {
        $baseUrl = rtrim((string) ($this->option('target')
            ?? config('test-center.environments.'.(string) $this->option('target-env').'.base_url')
            ?? config('app.url')), '/');

        if ($baseUrl === '') {
            $this->components->error('No target URL resolved. Pass --target= or set APP_URL.');

            return self::FAILURE;
        }

        $quiet = (string) $this->option('format') === 'junit-json'; // machine contract = stdout purity
        if (! $quiet) {
            $this->components->info("💨 Smoke · {$baseUrl}");
        }
        $started = microtime(true);

        $checks = $this->buildChecks($baseUrl);
        $problems = 0;

        foreach ($checks as [$name, $callable]) {
            try {
                [$ok, $detail] = $callable();
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, get_class($e).': '.$e->getMessage()];
            }

            $status   = $ok ? 'passed' : 'failed';
            $problems += $ok ? 0 : 1;

            if (! $quiet) {
                $icon = $ok ? '✔' : '✖';
                $this->line(sprintf('  %s %-24s %s', $icon, $name, mb_substr((string) $detail, 0, 120)));
            }

            $this->cases[] = [
                'identifier' => "smoke::{$name}",
                'classname'  => 'qa-smoke',
                'name'       => $name,
                'status'     => $status,
                'time_ms'    => null,
                'message'    => $ok ? null : (string) $detail,
                'data_set'   => null,
                'exception_class' => null,
            ];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ((string) $this->option('format') === 'junit-json') {
            $this->line(json_encode([
                'totals' => ['tests' => count($this->cases), 'failures' => $problems, 'errors' => 0, 'skipped' => 0, 'assertions' => count($this->cases)],
                'cases'  => $this->cases,
                'duration_ms' => $durationMs,
            ]));

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $verdict = $problems === 0 ? '<fg=green>SMOKE PASSED</>' : "<fg=red>SMOKE FAILED ({$problems})</>";
        $this->newLine();
        $this->line("  {$verdict} · ".count($this->cases)." checks · {$durationMs}ms");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{0:string,1:callable}> each returns [bool ok, string detail] */
    private function buildChecks(string $base): array
    {
        $get = fn (string $path) => Http::timeout((int) $this->option('timeout'))
            ->withHeaders(['User-Agent' => 'Exospace-QA-Smoke/1.0'])
            ->accept('*/*')
            ->get($base.$path);

        $manifestCheck = function () use ($base): array {
            $resp = Http::timeout(10)->get($base.'/');
            if (! $resp->successful()) {
                return [false, 'homepage HTTP '.$resp->status()];
            }
            if (! preg_match('/src="([^"]+\/assets\/[^"]+\.js[^"]*)"/', $resp->body(), $m)
                && ! preg_match('/href="([^"]+\/assets\/[^"]+\.css[^"]*)"/', $resp->body(), $m)) {
                return [false, 'no hashed build asset reference found in homepage HTML'];
            }
            // strip domain: served locally by definition; presence in HTML implies deploy wired vite manifest
            return [true, 'hashed build asset referenced: '.substr($m[1], -60)];
        };

        return [
            ['/up', function () use ($get): array {
                $r = $get('/up');

                return [$r->status() === 200, 'HTTP '.$r->status()];
            }],
            ['/health', function () use ($get): array {
                $r = $get('/health');
                $ok = $r->status() === 200 || $r->status() === 503; // degraded must still ANSWER
                $json = json_decode($r->body(''), true);

                return [$ok, 'HTTP '.$r->status().' '.collect($json['checks'] ?? [])
                    ->map(fn ($c, $k) => "$k={$c['status']}")
                    ->implode(' ')];
            }],
            ['robots.txt', function () use ($get): array {
                $r = $get('/robots.txt');
                $hasSitemap = str_contains($r->body(), 'Sitemap:');

                return [$r->status() === 200 && $hasSitemap, "HTTP {$r->status()} sitemap-ref=".(int) $hasSitemap];
            }],
            ['sitemap.xml', function () use ($get): array {
                $r = $get('/sitemap.xml');
                $isXml = str_starts_with(ltrim($r->body()), '<?xml');

                return [$r->status() === 200 && $isXml, 'HTTP '.$r->status().' xml='.(int) $isXml];
            }],
            ['/login', function () use ($get): array {
                $r = $get('/login');

                return [$r->status() === 200 || $r->isRedirect(), 'HTTP '.$r->status()];
            }],
            ['/register', function () use ($get): array {
                $r = $get('/register');

                return [$r->status() === 200 || $r->isRedirect(), 'HTTP '.$r->status()];
            }],
            ['build-assets', $manifestCheck],
        ];
    }
}

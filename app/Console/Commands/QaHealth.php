<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * qa:health — PRODUCTION-SAFE READ-ONLY in-process diagnostics.
 *
 * The ONLY profile designed to execute inside the production container
 * (read-only probes; zero mutation). Mirrors the questions a human would ask
 * after a deploy: app booted? DB answers? Redis answers? queue draining?
 * scheduler heartbeat fresh? disk sane?
 */
class QaHealth extends Command
{
    protected $signature = 'qa:health
                            {--format=text : text|junit-json}
                            {--max-scheduler-age-min=75 : beyond this, the scheduler is considered stale}
                            {--max-failed-jobs=50 : warning threshold for failed_jobs depth}';

    protected $description = 'Run read-only production health checks inside this application instance';

    private array $cases = [];

    public function handle(): int
    {
        $started = microtime(true);
        $this->add('app-boot', fn () => [true, 'Laravel '.app()->version().' env='.app()->environment()]);
        $this->add('db-select1', function (): array {
            $one = DB::selectOne('SELECT 1 as one');

            return [$one !== null && (int) $one->one === 1, 'driver='.DB::connection()->getDriverName()];
        });
        $this->add('cache-roundtrip', function () {
            $key = 'qa:health:'.bin2hex(random_bytes(4));
            $put = Cache::store()->put($key, 'probe', 30) === null ? true : true; // some drivers return void/null
            $got = Cache::store()->get($key);
            Cache::store()->forget($key);

            return [$got === 'probe', 'store='.config('cache.default').' roundtrip='.(int) ($got === 'probe')];
        });
        $this->add('redis-ping', function () {
            try {
                $pong = Redis::connection()->ping();

                return [str_contains(strtolower((string) $pong), 'pong') || $pong === true || $pong === 1 || $pong === '+PONG', 'reply='.(string) json_encode($pong)];
            } catch (\Throwable $e) {
                // Redis optional if session/cache/queue drivers don't use it.
                $needed = str_contains((string) config('session.driver'), 'redis')
                       || str_contains((string) config('cache.default'), 'redis')
                       || str_contains((string) config('queue.connections.'.config('queue.default').'.driver'), 'redis');

                return [! $needed, 'unavailable; not required by current drivers'];
            }
        });
        $this->add('queue-depth', function () {
            $pending = DB::table((string) config('queue.connections.' . config('queue.default') . '.table', 'jobs'))->count();
            $failed  = DB::table('failed_jobs')->count();
            $limit   = (int) $this->option('max-failed-jobs');

            return [$failed <= $limit, "pending={$pending} failed={$failed} (warn>{$limit})"];
        });
        $this->add('scheduler-heartbeat', function () {
            $stamp = Cache::get('scheduler-last-run');   // JobHeartbeatService contract

            if (! $stamp) {
                return [false, 'no scheduler heartbeat key present — is schedule:work / cron attached?'];
            }
            $ageMin = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($stamp));
            $limit  = (int) $this->option('max-scheduler-age-min');

            return [$ageMin <= $limit, "age={$ageMin}min (warn>{$limit})"];
        });
        $this->add('disk-space', function () {
            $freeMb = (int) (@disk_free_space(storage_path()) ?: 0) / 1024 / 1024;

            return [$freeMb > 500, number_format($freeMb).'MB free at storage/'];
        });

        $quiet = (string) $this->option('format') === 'junit-json';

        foreach ($this->probes as [$name, $callable]) {
            try {
                [$ok, $detail] = $callable();
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, class_basename($e).': '.$e->getMessage()];
            }

            $problems += $ok ? 0 : 1;
            if (! $quiet) {
                $this->line(sprintf('  %s %-22s %s', $ok ? '✔' : '✖', $name, mb_substr($detail, 0, 110)));
            }

            $this->cases[] = [
                'identifier' => "health::{$name}", 'classname' => 'qa-health', 'name' => $name,
                'status'     => $ok ? 'passed' : 'failed', 'time_ms' => null,
                'message'    => $ok ? null : (string) $detail,
                'data_set'   => null, 'exception_class' => null,
            ];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ((string) $this->option('format') === 'junit-json') {
            $this->line(json_encode([
                'totals' => ['tests' => count($this->cases), 'failures' => $problems, 'errors' => 0, 'skipped' => 0, 'assertions' => count($this->cases)],
                'cases' => $this->cases, 'duration_ms' => $durationMs,
            ]));

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $verdict = $problems === 0 ? '<fg=green>HEALTHY</>' : "<fg=red>DEGRADED ({$problems})</>";
        $this->newLine();
        $this->line("  {$verdict} · ".count($this->cases)." checks · {$durationMs}ms");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @var list<array{0:string,1:callable}> */
    private array $probes = [];
    private int $problems = 0;

    private function add(string $name, callable $fn): void
    {
        $this->probes[] = [$name, $fn];
    }
}

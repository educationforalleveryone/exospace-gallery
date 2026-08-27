<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * OpsCenter — RedisDiagnostics (Iteration 3).
 *
 * redis.connectivity
 *
 * Redis carries cache, sessions AND queues in this deployment, so a Redis
 * outage is a full-stack event. This diagnostic distinguishes unreachable /
 * authentication failure / write-rejections (MISCONF, read-only replica) and
 * reports latency + memory pressure where the server offers them.
 *
 * Read-only: the probe writes a single throwaway key with a 10-second TTL
 * and deletes it — the same probe /health and OpsHealthService already use —
 * plus PING and INFO. No config changes, no flushes, ever.
 */
class RedisDiagnostics implements RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return $this->connectivity();
    }

    private function connectivity(): DiagnosticResult
    {
        $findings = [];

        // 1) PING round-trip with latency.
        $pingMs = null;
        $failure = null;

        try {
            $start = microtime(true);
            Redis::connection()->ping();
            $pingMs = (int) round((microtime(true) - $start) * 1000);

            $findings[] = [
                'label' => 'PING round-trip',
                'status' => $pingMs > 100 ? 'warn' : 'pass',
                'detail' => "Redis answered PING in {$pingMs} ms.".($pingMs > 100 ? ' Latency is high — on a same-host deployment this usually means Redis is under memory/CPU pressure.' : ''),
            ];
        } catch (Throwable $e) {
            $failure = $e;
            $findings[] = [
                'label' => 'PING round-trip',
                'status' => 'fail',
                'detail' => 'Redis did not answer: '.mb_substr($e->getMessage(), 0, 240),
            ];
        }

        if ($failure !== null) {
            $mode = $this->classifyFailure($failure);

            return DiagnosticResult::fromFindings(
                'Redis unreachable'.($mode !== 'unknown' ? ' — '.$this->modeLabel($mode) : ''),
                $findings,
                $this->failureInterpretation($mode),
                ['container.health', 'queue.health'],
            );
        }

        // 2) Write/read/delete probe (Redis carries sessions + cache here).
        try {
            $key = 'ops:diag:'.uniqid('', true);
            Redis::connection()->setex($key, 10, 'probe');
            $ok = Redis::connection()->get($key) === 'probe';
            Redis::connection()->del($key);

            $findings[] = [
                'label' => 'Write/read/delete probe (10s TTL key)',
                'status' => $ok ? 'pass' : 'fail',
                'detail' => $ok
                    ? 'Round-trip write, read and delete succeeded — Redis accepts writes (cache and sessions can function).'
                    : 'The probe key did not read back — Redis answered PING but is not behaving like a writable primary. A read-only replica or a MISCONF state produces exactly this.',
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $isWriteRejection = str_contains($message, 'MISCONF') || str_contains($message, 'READONLY');

            $findings[] = [
                'label' => 'Write/read/delete probe (10s TTL key)',
                'status' => 'fail',
                'detail' => ($isWriteRejection ? 'Redis is REFUSING WRITES. ' : 'Probe failed: ').mb_substr($message, 0, 240),
            ];
        }

        // 3) Memory pressure (INFO — tolerant; not all deployments expose it).
        try {
            $info = Redis::connection()->info('memory');
            $used = $info['used_memory_human'] ?? null;
            $max = $info['maxmemory_human'] ?? null;

            if ($used !== null) {
                $findings[] = [
                    'label' => 'Memory usage',
                    'status' => 'pass',
                    'detail' => 'Used: '.$used.($max && $max !== '0B' ? ' (maxmemory: '.$max.')' : ' (no maxmemory limit configured)'),
                ];
            } else {
                $findings[] = [
                    'label' => 'Memory usage',
                    'status' => 'skip',
                    'detail' => 'INFO memory not available through this connection.',
                ];
            }
        } catch (Throwable) {
            $findings[] = [
                'label' => 'Memory usage',
                'status' => 'skip',
                'detail' => 'INFO command unavailable — PING and the write probe are authoritative for connectivity.',
            ];
        }

        $hasWarnOrFail = (bool) collect($findings)->first(fn ($f) => in_array($f['status'], ['warn', 'fail'], true));

        return DiagnosticResult::fromFindings(
            $hasWarnOrFail ? 'Redis reachable but degraded' : "Redis healthy — {$pingMs} ms round-trip",
            $findings,
            $hasWarnOrFail
                ? 'Redis is reachable but something is off (latency, writes, or memory). Because this deployment uses Redis for cache, sessions AND queues, degradation here bleeds into everything — the queue diagnostic shows the job-side impact.'
                : 'Redis is up, fast, accepts writes and reports no memory pressure. Cache, sessions and the queue transport are functioning at the Redis level. If queue jobs still fail, the cause is on the job/worker side (see Queue health).',
            ['queue.health', 'app.cache'],
        );
    }

    private function classifyFailure(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Connection refused') || str_contains($message, 'php_network_getaddresses') || str_contains($message, 'getaddrinfo failed') => 'refused',
            str_contains($message, 'NOAUTH') || str_contains($message, 'invalid password') || str_contains($message, 'ERR Client sent AUTH') => 'auth',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            default => 'unknown',
        };
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'refused' => 'server unreachable',
            'auth' => 'authentication rejected',
            'timeout' => 'connection timed out',
            default => 'connection failed',
        };
    }

    private function failureInterpretation(string $mode): string
    {
        return match ($mode) {
            'refused' => 'Nothing is answering at the configured Redis host/port. In this deployment Redis is a Coolify-managed resource: check its row on the Applications page (live Coolify status) — if the resource is stopped or restarting, every cache/session/queue operation in the application is failing right now. This is a platform-level outage.',
            'auth' => 'Redis is REACHABLE but rejected the credentials. This is the "healthy Redis, wrong password" case: REDIS_PASSWORD was rotated (or the app was pointed at a different Redis) and the application environment was not updated. Fix the environment variables in Coolify and redeploy.',
            'timeout' => 'Redis accepted the connection attempt but did not answer in time — usually extreme memory pressure (swapping) or a frozen process. A restart of the Redis resource (from Coolify) is the usual remediation, but check memory first.',
            default => 'The connection failed in an unexpected way. The raw driver message is in the findings. If the message mentions MISCONF, Redis is refusing writes because its persistence (RDB save) is failing — usually a full disk (see Disk usage).',
        };
    }
}

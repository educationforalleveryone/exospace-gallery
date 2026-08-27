<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Http\Controllers\Controller;
use App\Services\TestCenter\RunRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/control-center/runs
 *
 * Ingestion endpoint for CI runners / remote executors that push JUnit
 * results INTO the Control Center so history, flaky detection and release
 * readiness have data even when execution happens off-app.
 *
 * Fail-closed conventions (mirroring /api/ops/ingest):
 *   - QA_INGEST_TOKEN unset     → 404 (endpoint "does not exist")
 *   - wrong X-QA-Token          → 401
 *   - malformed artifact        → 422 (nothing is recorded)
 *
 * Request shape (multipart/form-data preferred for artifacts):
 *   junit             : file upload (JUnit XML)              [required]
 *   profile           : string key                           [required]
 *   environment       : ci|local|staging                     [default ci]
 *   git_branch/commit : strings
 *   trigger           : manual|ci|api|schedule               [default ci]
 *   runner            : string label
 *   ci_run_url        : link to the pipeline run
 */
class IngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = (string) config('test-center.ingest_token');

        // Fail-closed: without configuration pretend the endpoint is absent.
        if ($token === '') {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        if (! hash_equals($token, (string) $request->header('X-QA-Token'))) {
            return response()->json(['message' => 'Invalid ingest token.'], 401);
        }

        $allowed = RateLimiter::attempt(
            'qa-ingest:'.$request->ip(),
            10,
            function () use ($request): JsonResponse {
                return $this->ingest($request);
            },
            60,
        );

        if ($allowed === false) {
            return response()->json(['message' => 'Too many ingest attempts. Retry shortly.'], 429);
        }

        return $allowed;
    }

    private function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'junit'       => ['required', 'file'],
            'profile'     => ['required', 'string', 'max:64', function (string $attr, mixed $value, \Closure $fail) {
                if (! config()->has("test-profiles.profiles.{$value}")) {
                    $fail("Unknown profile [{$value}].");
                }
            }],
            'environment' => ['nullable', 'in:ci,local,staging'],
            'trigger'     => ['nullable', 'in:manual,ci,api,schedule'],
            'git_branch'  => ['nullable', 'string', 'max:120'],
            'git_commit'  => ['nullable', 'string', 'max:40'],
            'git_tag'     => ['nullable', 'string', 'max:120'],
            'runner'      => ['nullable', 'string', 'max:64'],
            'ci_run_url'  => ['nullable', 'url', 'max:500'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var \Illuminate\Http\UploadedFile|null $artifact */
        $artifact = $request->file('junit');

        if ($artifact === null || ! $artifact->isValid()) {
            return response()->json(['message' => 'Unreadable artifact upload.'], 422);
        }

        $maxKb = (int) config('test-center.max_artifact_kb', 20480);
        if (($artifact->getSize() ?? 0) > $maxKb * 1024) {
            return response()->json([
                'message' => "Artifact exceeds {$maxKb} KB limit.",
            ], 422);
        }

        $profileKey = (string) $validated['profile'];

        try {
            $tmpPath = (string) $artifact->getRealPath();

            if ($tmpPath === '' || ! is_file($tmpPath)) {
                return response()->json(['message' => 'Unreadable artifact upload path.'], 422);
            }

            // RunRecorder refuses zero-case artifacts by design — nothing is
            // ever recorded as "passed" without an artifact saying so.
            $run = app(RunRecorder::class)->record([
                'profile'     => $profileKey,
                'environment' => $validated['environment'] ?? 'ci',
                'safety'      => (string) config("test-profiles.profiles.{$profileKey}.safety", 'test-only'),
                'trigger'     => $validated['trigger'] ?? 'ci',
                'runner'      => $validated['runner'] ?? 'github-actions',
                'git_branch'  => $validated['git_branch'] ?? null,
                'git_commit'  => $validated['git_commit'] ?? null,
                'git_tag'     => $validated['git_tag'] ?? null,
                'ci_run_url'  => $validated['ci_run_url'] ?? null,
            ], $tmpPath, [
                'duration_ms' => isset($validated['duration_ms']) ? (int) $validated['duration_ms'] : null,
                'started_at'  => now(),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Artifact rejected: '.$e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message'   => 'Run ingested.',
            'run_id'    => $run->id,
            'status'    => $run->status,
            'totals'    => [
                'total'   => $run->total,
                'passed'  => $run->passed,
                'failed'  => $run->failed,
                'errored' => $run->errored,
                'skipped' => $run->skipped,
            ],
            'failure_class' => $run->failure_class,
        ], 201);
    }
}

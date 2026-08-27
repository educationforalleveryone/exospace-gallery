<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Actions\OpsActionService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * OpsCenter — OpsQueueController (Iteration 10).
 *
 * The failed-jobs BROWSER: what the queue.failed-jobs diagnostic
 * summarizes (counts + top-5 groups), this page shows in full — every
 * failed job, its human name, its age, its leading exception line, and
 * (super-admins only) the Retry…/Forget… doors into the action
 * framework.
 *
 * This closes the last terminal workflow the platform itself used to
 * recommend: the diagnostic's guidance previously ended with "retry
 * deliberately (php artisan queue:retry from a terminal)". After this
 * iteration the terminal sentence is gone and the sentence's subject —
 * the failed-jobs table — is first-class in the control plane.
 *
 * Contract:
 *   - READ-ONLY page: viewer-visible (the same bar as every other read
 *     surface — viewing failures is diagnosis, not intervention).
 *   - Fail-soft on a missing failed_jobs table (fresh install before
 *     migrations): the page renders with a notice, never a 500.
 *   - Payloads and exception traces are shown EXCERPTED and behind
 *     disclosure toggles — they may contain user data; the list view
 *     carries only the job name, queue, connection, age and the FIRST
 *     exception line (the same 220-char discipline the diagnostic uses).
 *   - The buttons are links to confirm pages, never direct POSTs — the
 *     four-layer security model (route group → allow-list → password →
 *     typed phrase) lives in OpsActionController and OpsActionService.
 */
class OpsQueueController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly OpsActionService $actions,
    ) {}

    /**
     * GET /ops/queue — the failed-jobs list, newest first, optionally
     * filtered to one queue (?queue=…).
     */
    public function index(Request $request): View
    {
        $queueFilter = trim((string) $request->query('queue', ''));
        if (strlen($queueFilter) > 100) {
            $queueFilter = substr($queueFilter, 0, 100);
        }

        $available = $this->tableAvailable();
        $jobs = $this->jobs($queueFilter);
        $summary = $this->summary();
        $queues = $this->queueCounts();

        return view('ops.queue', [
            'jobs' => $jobs,
            'summary' => $summary,
            'queues' => $queues,
            'queueFilter' => $queueFilter !== '' ? $queueFilter : null,
            'tableAvailable' => $available,
            'actionsEnabled' => $this->actions->enabled(),
        ]);
    }

    /**
     * Does the failed_jobs table exist and open? (Fresh installs before
     * the base migrations have no table; the page still renders.)
     */
    private function tableAvailable(): bool
    {
        try {
            DB::table('failed_jobs')->count();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The paginated job list, newest first. Each row is shaped for the
     * view: the human job name parsed from the payload, the first
     * exception line, and the age.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function jobs(?string $queueFilter): LengthAwarePaginator
    {
        try {
            $page = DB::table('failed_jobs')
                ->when($queueFilter !== null && $queueFilter !== '',
                    fn ($q) => $q->where('queue', $queueFilter))
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->paginate(self::PAGE_SIZE);
        } catch (Throwable) {
            // Fail-soft: an empty paginator of the right shape.
            return new LengthAwarePaginator([], 0, self::PAGE_SIZE, 1, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        $rows = collect($page->items())->map(function ($row) {
            $payload = (string) ($row->payload ?? '');
            $exception = (string) ($row->exception ?? '');
            $exceptionLines = explode("\n", $exception);
            $firstLine = trim($exceptionLines[0] ?? '');

            return [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'job' => OpsActionService::jobName($payload),
                'first_exception' => mb_substr($firstLine, 0, 220),
                'exception_excerpt' => mb_substr($exception, 0, 2000),
                'payload_excerpt' => mb_substr($payload, 0, 600),
                'failed_at' => $row->failed_at
                    ? Carbon::parse($row->failed_at)
                    : null,
            ];
        });

        return new LengthAwarePaginator(
            $rows->all(),
            $page->total(),
            self::PAGE_SIZE,
            $page->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return array{total: int, last_24h: int, oldest: ?string, unfiltered_total: int}
     */
    private function summary(): array
    {
        try {
            $total = (int) DB::table('failed_jobs')->count();
            $last24 = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            $oldest = DB::table('failed_jobs')
                ->orderBy('failed_at')
                ->value('failed_at');

            return [
                'total' => $total,
                'last_24h' => $last24,
                'oldest' => $oldest !== null
                    ? Carbon::parse($oldest)->diffForHumans()
                    : null,
                'unfiltered_total' => $total,
            ];
        } catch (Throwable) {
            return ['total' => 0, 'last_24h' => 0, 'oldest' => null, 'unfiltered_total' => 0];
        }
    }

    /**
     * Per-queue counts, descending — the filter chips above the list.
     *
     * @return array<int, array{queue: string, count: int}>
     */
    private function queueCounts(): array
    {
        try {
            return DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as n'))
                ->groupBy('queue')
                ->orderByDesc('n')
                ->limit(12)
                ->get()
                ->map(fn ($row) => [
                    'queue' => (string) $row->queue,
                    'count' => (int) $row->n,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}

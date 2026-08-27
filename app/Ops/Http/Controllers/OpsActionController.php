<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Actions\OpsActionRegistry;
use App\Ops\Actions\OpsActionService;
use App\Ops\Models\OpsApplication;
use App\Models\ProcessedWebhook;
use App\Models\AdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * OpsCenter — OpsActionController (Iteration 3).
 *
 * The write surface: every state-changing operation the control plane
 * exposes. The security model has FOUR layers, all enforced here:
 *
 *   1. Route group: auth + verified + super_admin + mfa (same bar as
 *      Master Control) + throttle:10,1.
 *   2. Allow-list: the action id must exist in OpsActionRegistry — there
 *      is no generic action endpoint.
 *   3. Inline password verification (NOT the framework password.confirm
 *      middleware: its intended()-redirect replays POST routes as GET and
 *      405s — a latent bug this module deliberately avoids). The password
 *      is validated with the same Auth::guard('web')->validate() primitive
 *      ConfirmablePasswordController uses.
 *   4. Typed confirmation phrase ("type RESTART to confirm") — the explicit
 *      "I understand the consequences" step the brief demands for
 *      high-risk operations.
 *
 * Execution then happens in OpsActionService, which audits
 * (AdminAuditLog ops.action.executed) and announces (Slack via the existing
 * OperationalAlertService) regardless of outcome.
 *
 * risk=none actions (platform.sync) skip layers 3-4: they change nothing
 * outside the control plane's own read state.
 */
class OpsActionController extends Controller
{
    public function __construct(
        private readonly OpsActionService $actions,
    ) {}

    /**
     * GET /ops/actions — the actions hub: catalog, failed webhook panel,
     * failed-jobs pointer (Iteration 10), recent executed actions (from
     * the audit ledger).
     */
    public function index(Request $request): View
    {
        $failedWebhooks = collect();
        try {
            $failedWebhooks = ProcessedWebhook::query()
                ->where('status', 'failed')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();
        } catch (Throwable) {
            // ledger table unavailable — panel renders empty.
        }

        $failedJobCount = null;
        try {
            $failedJobCount = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            // failed_jobs unavailable — the queue card renders with '—'.
        }

        $recentActions = collect();
        try {
            $recentActions = AdminAuditLog::query()
                ->where('action', 'ops.action.executed')
                ->orderByDesc('created_at')
                ->limit(15)
                ->get();
        } catch (Throwable) {
            // audit ledger unavailable — panel renders empty.
        }

        return view('ops.actions', [
            'actions' => OpsActionRegistry::all(),
            'enabled' => $this->actions->enabled(),
            'coolifyApps' => OpsApplication::query()
                ->where(function ($q) {
                    $q->where('provider', 'coolify')->orWhere('is_self', true);
                })
                ->where(function ($q) {
                    $q->whereNotNull('provider_uuid')->orWhere('is_self', true);
                })
                ->orderByDesc('is_self')
                ->orderBy('name')
                ->get(),
            'failedWebhooks' => $failedWebhooks,
            'failedJobCount' => $failedJobCount,
            'recentActions' => $recentActions,
        ]);
    }

    /**
     * GET /ops/actions/{action}/confirm — the interstitial: consequences in
     * plain language, typed phrase + password. Nothing executes on GET.
     */
    public function confirm(Request $request, string $action)
    {
        $definition = OpsActionRegistry::get($action);

        if ($definition === null || ! $this->actions->enabled()) {
            abort(404, 'Unknown action.');
        }

        if ($definition['risk'] === OpsActionRegistry::RISK_NONE) {
            // risk=none actions never show a confirm page.
            abort(404, 'This action does not require confirmation.');
        }

        // Resolve + validate the target so the consequences page shows the
        // REAL target, not a placeholder.
        if ($action === 'app.restart') {
            $application = OpsApplication::find((int) $request->query('app', 0));

            if ($application === null) {
                return redirect()
                    ->route('ops.actions.index')
                    ->withErrors(['action' => 'Pick an application to restart first.']);
            }

            return view('ops.action-confirm', [
                'actionId' => $action,
                'definition' => $definition,
                'application' => $application,
                'webhook' => null,
                'failedJob' => null,
            ]);
        }

        if ($action === 'webhook.replay') {
            $webhook = ProcessedWebhook::find((int) $request->query('webhook', 0));

            if ($webhook === null) {
                return redirect()
                    ->route('ops.actions.index')
                    ->withErrors(['action' => 'Pick a webhook to replay first.']);
            }

            return view('ops.action-confirm', [
                'actionId' => $action,
                'definition' => $definition,
                'application' => null,
                'webhook' => $webhook,
                'failedJob' => null,
            ]);
        }

        // Iteration 10 — queue.retry / queue.forget: the target is ONE
        // failed job, addressed by UUID (the identifier queue:retry and
        // queue:forget themselves accept — stable, non-enumerable).
        if ($action === 'queue.retry' || $action === 'queue.forget') {
            $job = $this->findFailedJob((string) $request->query('job', ''));

            if ($job === null) {
                return redirect()
                    ->route('ops.queue.index')
                    ->withErrors(['action' => 'That failed job no longer exists — it may have been retried or deleted already.']);
            }

            return view('ops.action-confirm', [
                'actionId' => $action,
                'definition' => $definition,
                'application' => null,
                'webhook' => null,
                'failedJob' => $job,
            ]);
        }

        abort(404, 'Unknown action.');
    }

    /**
     * POST /ops/actions/{action} — validate password + typed phrase, then
     * execute. risk=none actions (platform.sync) execute directly.
     */
    public function execute(Request $request, string $action): RedirectResponse
    {
        $definition = OpsActionRegistry::get($action);

        if ($definition === null || ! $this->actions->enabled()) {
            abort(404, 'Unknown action.');
        }

        $redirectBack = $this->redirectBackFor($action, $request);

        // risk=none: no password, no phrase — it changes nothing outside the
        // control plane's own read state (still throttled + audited).
        if ($definition['risk'] === OpsActionRegistry::RISK_NONE) {
            $result = $this->actions->execute($action, [], $request->user());

            return $redirectBack->with($result['ok'] ? 'success' : 'error', $result['message']);
        }

        $validated = $request->validate([
            'application' => ($action === 'app.restart' ? 'required' : 'nullable').'|integer',
            'webhook' => ($action === 'webhook.replay' ? 'required' : 'nullable').'|integer',
            'job' => (str_starts_with($action, 'queue.') ? 'required' : 'nullable').'|string|max:64',
            'confirm' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'job.required' => 'The failed job is missing — restart from the queue page.',
            'confirm.required' => 'Type the confirmation phrase to proceed.',
            'password.required' => 'Your password is required for this action.',
        ]);

        // Layer 3: password — ALWAYS, for every elevated action, even within
        // a recently-confirmed session (deliberately stricter than the
        // framework's 3-hour password.confirm window).
        $passwordOk = Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $validated['password'],
        ]);

        if (! $passwordOk) {
            return $redirectBack->withErrors(['password' => 'That is not your current password.']);
        }

        // Layer 4: typed phrase (exact match — the phrase names what will
        // happen; a mismatch means the operator did not read the page).
        if (hash_equals((string) $definition['confirmation_phrase'], trim((string) $validated['confirm'])) !== true) {
            return $redirectBack->withErrors(['confirm' => 'Confirmation phrase did not match — type it exactly as shown.']);
        }

        $target = [];
        if ($action === 'app.restart') {
            $target['application_id'] = (int) $validated['application'];
        }
        if ($action === 'webhook.replay') {
            $target['webhook_id'] = (int) $validated['webhook'];
        }
        if (str_starts_with($action, 'queue.')) {
            $target['failed_job_uuid'] = (string) $validated['job'];
        }

        $result = $this->actions->execute($action, $target, $request->user());

        $final = $action === 'app.restart'
            ? redirect()->route('ops.applications')
            : (str_starts_with($action, 'queue.')
                ? redirect()->route('ops.queue.index')
                : $redirectBack);

        return $final->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Where to send the operator back to on validation failures (the confirm
     * page, with its context preserved).
     */
    private function redirectBackFor(string $action, Request $request): RedirectResponse
    {
        if ($action === 'app.restart' && $request->input('application')) {
            return redirect()
                ->route('ops.actions.confirm', ['action' => $action, 'app' => (int) $request->input('application')])
                ->withInput($request->only('confirm'));
        }

        if ($action === 'webhook.replay' && $request->input('webhook')) {
            return redirect()
                ->route('ops.actions.confirm', ['action' => $action, 'webhook' => (int) $request->input('webhook')])
                ->withInput($request->only('confirm'));
        }

        if (str_starts_with($action, 'queue.') && $request->input('job')) {
            return redirect()
                ->route('ops.actions.confirm', ['action' => $action, 'job' => (string) $request->input('job')])
                ->withInput($request->only('confirm'));
        }

        return redirect()->route('ops.actions.index');
    }

    /**
     * Fetch ONE failed job by UUID for the confirm page — shaped the same
     * way OpsActionService::findFailedJob returns it, minus the raw
     * payload/exception blobs (the page shows excerpts).
     *
     * @return array{uuid: string, connection: string, queue: string, job: string, first_exception: string, failed_at: string}|null
     */
    private function findFailedJob(string $uuid): ?array
    {
        if ($uuid === '' || strlen($uuid) > 64) {
            return null;
        }

        try {
            $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $exception = (string) ($row->exception ?? '');

        return [
            'uuid' => (string) $row->uuid,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'job' => OpsActionService::jobName((string) ($row->payload ?? '')),
            'first_exception' => mb_substr(trim(explode("\n", $exception)[0] ?? ''), 0, 220),
            'failed_at' => (string) ($row->failed_at ?? ''),
        ];
    }
}

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

class OpsActionController extends Controller
{
    public function __construct(
        private readonly OpsActionService $actions,
    ) {}

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

    public function execute(Request $request, string $action): RedirectResponse
    {
        $definition = OpsActionRegistry::get($action);

        if ($definition === null || ! $this->actions->enabled()) {
            abort(404, 'Unknown action.');
        }

        $redirectBack = $this->redirectBackFor($action, $request);

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

        $passwordOk = Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $validated['password'],
        ]);

        if (! $passwordOk) {
            return $redirectBack->withErrors(['password' => 'That is not your current password.']);
        }

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

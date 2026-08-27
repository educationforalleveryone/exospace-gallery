<?php

declare(strict_types=1);

namespace App\Http\Controllers\ControlCenter;

use App\Http\Controllers\Controller;
use App\Jobs\RunQaProfile;
use App\Models\QaTestRun;
use App\Services\TestCenter\EnvironmentSafety;
use App\Services\TestCenter\TestProfileRegistry;
use Illuminate\Http\RedirectResponse;

/**
 * Starts a profile run from the dashboard.
 *
 * Capability detection keeps this honest:
 *   - On machines that actually have a runner (phpunit present, not the
 *     production image): creates run row QUEUED and dispatches RunQaProfile
 *     through the queue → status flips RUNNING → final state recorded.
 *   - On production (no dev deps, policy forbids suites anyway): the UI does
 *     not pretend — it hands the operator the pre-filled GitHub Actions
 *     dispatch link, which is where execution is *designed* to happen.
 */
class StartController extends Controller
{
    public function __construct(
        private readonly TestProfileRegistry $registry,
        private readonly EnvironmentSafety $safety,
    ) {}

    public function store(string $profileKey): RedirectResponse
    {
        if (! $this->registry->has($profileKey)) {
            return back()->withErrors("Unknown profile [{$profileKey}].");
        }

        $profile = $this->registry->profile($profileKey);

        // Strategy profiles without executors yet (smoke / production_health):
        // redirect to dispatch as well once available; for now block honestly.
        if (($profile['strategy'] ?? 'phpunit') !== 'phpunit') {
            return back()->with('warning', "Profile [{$profileKey}] executor ships with Iteration 3 (config registered already).");
        }

        $verdict = $this->safety->evaluate($profileKey, $profile, 'local');

        if ($verdict['allowed'] && $this->canExecuteLocally()) {
            $run = QaTestRun::create([
                'uuid'          => (string) \Str::uuid(),
                'profile'       => $profileKey,
                'environment'   => 'local',
                'safety'        => $profile['safety'],
                'trigger'       => 'api',
                'runner'        => 'dashboard-queue',
                'git_branch'    => null,
                'git_commit'    => null,
                'status'        => QaTestRun::STATUS_QUEUED,
            ]);

            RunQaProfile::dispatch($run->id);

            return redirect()
                ->route('control-center.run.show', $run)
                ->with('info', "Queued {$profile['label']} — status updates automatically.");
        }

        return redirect()->away($this->githubDispatchUrl($profileKey));
    }

    private function canExecuteLocally(): bool
    {
        return file_exists(base_path(config('test-center.phpunit_binary', 'vendor/bin/phpunit')))
            && ! app()->isProduction();
    }

    private function githubDispatchUrl(string $profileKey): string
    {
        // Empty repo slug → fall back to the generic actions page.
        $slug = config('test-center.github_repo', env('GITHUB_REPO', ''));

        return $slug !== ''
            ? "https://github.com/{$slug}/actions/workflows/test-profiles.yml?query=workflow%%3A%%22Test+Profiles%%22"
            : route('control-center.overview');
    }
}

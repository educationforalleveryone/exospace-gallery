<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Models\QaTestCaseResult;
use App\Models\QaTestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Self-verification for the Testing Control Center UI (QA Iteration 2).
 *
 * These tests are deliberately honest: they PROVE the gate, the pages and
 * the queued execution path — including the fail-closed behavior that makes
 * the whole section invisible without configuration.
 */
class ControlCenterUiTest extends TestCase
{
    use RefreshDatabase;

    private string $admin = 'qa-boss@exospace.gallery';

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts from a deterministic allowlist state.
        config()->set('test-center.admin_emails', [$this->admin]);
    }

    /* ── Access gate ───────────────────────────────────────────────────── */

    public function test_gate_is_fail_closed_when_allowlist_empty(): void
    {
        config()->set('test-center.admin_emails', []);

        // Guests are bounced by `auth` first (normal Laravel layering), but an
        // authenticated user must still see the section VANISH (404) — proving
        // an empty allowlist disables everything rather than locking everyone out.
        $user = \App\Models\User::factory()->create(['email' => 'someone@example.com']);
        $this->actingAs($user)->get('/control-center')->assertStatus(404);
        $this->actingAs($user)->get('/control-center/runs')->assertStatus(404);
    }

    public function test_guest_gets_redirected_to_login(): void
    {
        $response = $this->get('/control-center');

        $response->assertRedirect();   // bounced to the login screen
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_non_listed_user_is_forbidden(): void
    {
        $user = \App\Models\User::factory()->create(['email' => 'random@example.com']);

        $this->actingAs($user)->get('/control-center')->assertForbidden();
    }

    public function test_email_match_is_case_insensitive(): void
    {
        config()->set('test-center.admin_emails', ['QA-Boss@Exospace.Gallery']);
        $user = \App\Models\User::factory()->create(['email' => 'qa-boss@exospace.gallery']);

        $this->actingAs($user)->get('/control-center')->assertOk();
    }

    /* ── Overview wall ─────────────────────────────────────────────────── */

    public function test_overview_lists_all_profiles_with_latest_status(): void
    {
        $user  = \App\Models\User::factory()->create(['email' => $this->admin]);
        QaTestRun::factory()->create(['profile' => 'seo', 'status' => 'failed', 'failure_class' => 'infrastructure']);
        // Pinned-commit run created LAST so it is the "latest activity" source.
        QaTestRun::factory()->state(fn () => [
            'profile' => 'billing',
            'status'  => 'passed',
            'total'   => 248, 'passed' => 248,
            'git_commit' => str_repeat('a', 40), 'git_branch' => 'main',
        ])->create();

        $view = $this->actingAs($user)->get('/control-center');

        $view->assertOk()
             ->assertSee('Status Wall')
             ->assertSee('Quick Check')
             ->assertSee('Pre-Release')
             ->assertSee('Release Readiness')
             ->assertSee('PASSED')
             ->assertSee('FAILED')
             ->assertSee(substr(str_repeat('a', 40), 0, 7))
             // safety labels visible per card
             ->assertSee('prod-safe-read', false);
    }

    /* ── Runs index + filters ──────────────────────────────────────────── */

    public function test_runs_index_filters_by_profile_and_status(): void
    {
        $user = \App\Models\User::factory()->create(['email' => $this->admin]);
        QaTestRun::factory()->count(3)->create(['profile' => 'security', 'status' => 'passed']);
        QaTestRun::factory()->count(2)->create(['profile' => 'billing', 'status' => 'failed']);

        $this->actingAs($user)->get('/control-center/runs?profile=billing&status=failed')
            ->assertOk()
            ->assertSee('billing')
            ->assertDontSee('<td class="px-4 py-3 font-medium">security</td>', false);
    }

    /* ── Run detail: failure drill-down + intelligence hints ───────────── */

    public function test_run_detail_shows_failure_classification_and_history(): void
    {
        $user = \App\Models\User::factory()->create(['email' => $this->admin]);

        $green  = QaTestRun::factory()->create(['profile' => 'security', 'status' => 'passed']);
        $broken = QaTestRun::factory()->create([
            'profile' => 'security', 'status' => 'failed', 'failure_class' => 'infrastructure',
        ]);

        // A previously-green test that now fails with an infra-flavored error.
        $identifier = 'Tests\Feature\MfaReplayProtectionTest::test_valid_code_verifies';
        QaTestCaseResult::query()->create([
            'qa_test_run_id'  => $green->id,
            'test_identifier' => $identifier,
            'classname'       => 'Tests\Feature\MfaReplayProtectionTest',
            'method_name'     => 'test_valid_code_verifies',
            'status'          => 'passed',
        ]);
        QaTestCaseResult::query()->create([
            'qa_test_run_id'  => $broken->id,
            'test_identifier' => $identifier,
            'classname'       => 'Tests\Feature\MfaReplayProtectionTest',
            'method_name'     => 'test_valid_code_verifies',
            'status'          => 'error',
            'message'         => "SQLSTATE[HY000] connection refused\n\nat bootstrap…",
            'detail'          => 'PDO Exception: connection refused (mysql://…)',
            'exception_class' => 'PDOException',
        ]);

        $html = $this->actingAs($user)->get("/control-center/runs/{$broken->id}");

        $html->assertOk()
             ->assertSee('Test infrastructure failure', false)
             ->assertSee($identifier)
             ->assertSee('connection refused', false)
             ->assertSee('pass rate')
             ->assertSee('last green');
    }

    public function test_first_ever_failure_is_flagged_as_new(): void
    {
        $user   = \App\Models\User::factory()->create(['email' => $this->admin]);
        $broken = QaTestRun::factory()->create(['profile' => 'billing', 'status' => 'failed']);

        QaTestCaseResult::query()->create([
            'qa_test_run_id'  => $broken->id,
            'test_identifier' => 'Tests\X::test_brand_new_regression',
            'classname'       => 'Tests\X',
            'method_name'     => 'test_brand_new_regression',
            'status'          => 'failed',
            'message'         => 'Failed asserting tax rate.',
        ]);

        $this->actingAs($user)->get("/control-center/runs/{$broken->id}")
            ->assertOk()
            ->assertSee('first known appearance', false);
    }

    /* ── Artifact download ─────────────────────────────────────────────── */

    public function test_artifact_downloads_when_stored_and_404s_otherwise(): void
    {
        $user  = \App\Models\User::factory()->create(['email' => $this->admin]);
        $with  = QaTestRun::factory()->create(['meta' => ['artifact_path' => 'control-center/test/run.xml']]);
        $without = QaTestRun::factory()->create();

        \Illuminate\Support\Facades\Storage::disk('local')->put('control-center/test/run.xml', '<?xml version="1.0"?><testsuites/>');

        $download = $this->actingAs($user)->get("/control-center/runs/{$with->id}/artifact")->assertOk();

        $this->assertStringContainsString(
            'junit.xml',
            (string) $download->headers->get('content-disposition')
        );

        $this->actingAs($user)->get("/control-center/runs/{$without->id}/artifact")->assertNotFound();
    }

    /* ── Start endpoint: honest capability handling ────────────────────── */

    public function test_start_queues_local_execution_when_runner_available(): void
    {
        Queue::fake();

        $user = \App\Models\User::factory()->create(['email' => $this->admin]);

        // phpunit binary exists in any dev checkout; tests never run as production env.
        $response = $this->actingAs($user)
            ->post('/control-center/profiles/quick_check/start');

        $run = QaTestRun::query()->where('profile', 'quick_check')->sole();

        $this->assertSame(QaTestRun::STATUS_QUEUED, $run->status);
        $response->assertRedirect();

        Queue::assertPushed(\App\Jobs\RunQaProfile::class);
    }

    public function test_start_refuses_unknown_profile_via_route_whitelist(): void
    {
        $user = \App\Models\User::factory()->create(['email' => $this->admin]);

        // The controller owns validation and answers with a friendly bounce.
        $this->actingAs($user)
            ->post('/control-center/profiles/not_a_real_profile/start')
            ->assertRedirect()
            ->assertSessionHasErrors();
    }
}

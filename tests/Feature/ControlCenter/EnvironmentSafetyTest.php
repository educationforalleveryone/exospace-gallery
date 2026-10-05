<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Services\TestCenter\EnvironmentSafety;
use Tests\TestCase;

class EnvironmentSafetyTest extends TestCase
{
    private EnvironmentSafety $safety;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safety = new EnvironmentSafety;
    }

    public function test_test_only_suites_are_refused_on_production_with_lockdown_message(): void
    {
        $verdict = $this->safety->evaluate('quick_check', config('test-profiles.profiles.quick_check'), 'production');

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('Production is protected', (string) $verdict['reason']);
        $this->assertStringContainsString('test-profiles.yml', (string) $verdict['remediation']);
    }

    public function test_prod_safe_read_profile_is_allowed_on_production(): void
    {
        $verdict = $this->safety->evaluate('smoke', config('test-profiles.profiles.smoke'), 'production');

        $this->assertTrue($verdict['allowed'], (string) $verdict['reason']);
    }

    public function test_profile_cannot_reach_production_by_listing_it_in_its_own_target_list(): void
    {
        // Pins the policy that replaced the dead "target list wins early"
        // branch: a misconfigured test-only profile claiming production as a
        // target must STILL be stopped by the safety-class lockdown.
        $profile = config('test-profiles.profiles.quick_check');
        $profile['target_environments'] = ['production'];

        $verdict = $this->safety->evaluate('quick_check', $profile, 'production');

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('Production is protected', (string) $verdict['reason']);
    }

    public function test_target_list_mismatch_is_refused(): void
    {
        // smoke deliberately targets staging+production only.
        $verdict = $this->safety->evaluate('smoke', config('test-profiles.profiles.smoke'), 'local');

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('targets staging/production, not local', (string) $verdict['reason']);
    }

    public function test_unknown_environment_fails_closed(): void
    {
        $verdict = $this->safety->evaluate('quick_check', config('test-profiles.profiles.quick_check'), 'planet-mars');

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('Unknown target environment', (string) $verdict['reason']);
        $this->assertStringContainsString('local, ci, staging, production', (string) $verdict['remediation']);
    }

    public function test_staging_refuses_suite_execution_until_explicitly_enabled(): void
    {
        config()->set('test-center.environments.staging.allow_suite_execution', false);

        $verdict = $this->safety->evaluate('quick_check', config('test-profiles.profiles.quick_check'), 'staging');

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('does not allow suite execution', (string) $verdict['reason']);
        $this->assertStringContainsString('TEST_CENTER_STAGING_SUITES', (string) $verdict['remediation']);
    }

    public function test_prod_safe_read_probe_bypasses_the_staging_suite_gate(): void
    {
        // Read-only probes never rebuild/mutate the data store, so the suite
        // gate must not apply to them even with suites disabled.
        config()->set('test-center.environments.staging.allow_suite_execution', false);

        $verdict = $this->safety->evaluate('production_health', config('test-profiles.profiles.production_health'), 'staging');

        $this->assertTrue($verdict['allowed'], (string) $verdict['reason']);
    }

    public function test_may_execute_matches_evaluate_verdict(): void
    {
        $this->assertTrue($this->safety->mayExecute('smoke', config('test-profiles.profiles.smoke'), 'production'));
        $this->assertFalse($this->safety->mayExecute('quick_check', config('test-profiles.profiles.quick_check'), 'production'));
    }
}

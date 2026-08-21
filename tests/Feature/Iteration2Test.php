<?php

declare(strict_types=1);

/**
 * ITERATION-2 regression tests.
 *
 * Verifies:
 *   - ProrationService reads prices from config('plans.display.*.price') (AUDIT-P1-2.7)
 *   - Skeleton + EmptyState + Tooltip + Toast Blade components render without errors
 *
 * Run: php artisan test --filter=Iteration2Test
 */

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Iteration2Test extends TestCase
{
    use RefreshDatabase;

    // ── ProrationService ─────────────────────────────────────────────────

    /**
     * AUDIT-P1-2.7: ProrationService should read plan prices from config
     * instead of the old hardcoded PLAN_PRICES constant. Verify the prices
     * match what config/plans.php declares.
     */
    public function test_audit_p12_7_proration_reads_prices_from_config(): void
    {
        $user = User::factory()->pro()->create([
            'plan_started_at'  => now()->subDays(15),
            'plan_expires_at'  => now()->addDays(15),
        ]);

        $service = app(ProrationService::class);
        $result = $service->calculateUpgradeCredit($user, 'studio');

        // Pro price from config: 29. Studio price from config: 99.
        // 15 days remaining out of 30 = 0.5 fraction.
        // Credit = 29 * 0.5 = 14.5. Adjusted = max(0, 99 - 14.5) = 84.5.
        $this->assertEquals(29.0, (float) config('plans.display.pro.price'), 'Sanity: config pro price is 29');
        $this->assertEquals(99.0, (float) config('plans.display.studio.price'), 'Sanity: config studio price is 99');

        $this->assertEquals(14.5, $result['credit_amount'], 'Proration credit = 50% of Pro price', 0.01);
        $this->assertEquals(84.5, $result['new_price'], 'Adjusted Studio price = 99 - 14.5', 0.01);
        $this->assertStringContainsString('15 remaining days', $result['credit_description']);
    }

    /**
     * AUDIT-P1-2.7: When config prices change at runtime, ProrationService
     * should reflect the new prices without code changes. This proves the
     * service reads from config, not from a hardcoded constant.
     */
    public function test_audit_p12_7_proration_reflects_runtime_config_changes(): void
    {
        config(['plans.display.pro.price' => 50]); // Override at runtime
        config(['plans.display.studio.price' => 200]);

        $user = User::factory()->pro()->create([
            'plan_started_at'  => now()->subDays(15),
            'plan_expires_at'  => now()->addDays(15),
        ]);

        $service = app(ProrationService::class);
        $result = $service->calculateUpgradeCredit($user, 'studio');

        // 15 days remaining out of 30 = 0.5 fraction.
        // Credit = 50 * 0.5 = 25. Adjusted = max(0, 200 - 25) = 175.
        $this->assertEquals(25.0, $result['credit_amount'], 'Proration should reflect runtime config override', 0.01);
        $this->assertEquals(175.0, $result['new_price'], 'Adjusted price should reflect runtime config override', 0.01);
    }

    /**
     * AUDIT-P1-2.7: Unknown plan returns 0 (no crash). This is the fallback
     * for misconfigured plans.
     */
    public function test_audit_p12_7_proration_handles_unknown_plan_gracefully(): void
    {
        $user = User::factory()->create([
            'plan'             => 'free',
            'plan_started_at'  => null,
            'plan_expires_at'  => null,
        ]);

        $service = app(ProrationService::class);
        $result = $service->calculateUpgradeCredit($user, 'pro');

        $this->assertEquals(0.0, $result['credit_amount']);
        $this->assertStringContainsString('No credit', $result['credit_description']);
    }

    // ── Blade components ─────────────────────────────────────────────────

    /**
     * AUDIT-P1-2.2: <x-skeleton> should render with all variant options
     * without throwing. Verifies the component is autoloaded and the
     * variant class map is complete.
     */
    public function test_audit_p12_2_skeleton_renders_all_variants(): void
    {
        $variants = ['text', 'row', 'card', 'chart', 'avatar', 'button'];

        foreach ($variants as $variant) {
            $rendered = view('components.skeleton', ['variant' => $variant])->render();
            $this->assertStringContainsString('animate-shimmer', $rendered, "Skeleton variant={$variant} should have animate-shimmer class");
            $this->assertStringContainsString('role="status"', $rendered, "Skeleton variant={$variant} should have role=status for screen readers");
        }
    }

    /**
     * AUDIT-P1-2.2: <x-skeleton count="N"> should render N child divs.
     */
    public function test_audit_p12_2_skeleton_count_renders_multiple(): void
    {
        $rendered = view('components.skeleton', ['variant' => 'row', 'count' => 5])->render();

        // The component wraps N child divs in a parent with role=status.
        // Count the inner divs with the shimmer class.
        $shimmerCount = substr_count($rendered, 'animate-shimmer');
        $this->assertEquals(5, $shimmerCount, 'Skeleton with count=5 should render 5 shimmer divs');
    }

    /**
     * AUDIT-P1-2.3: <x-empty-state> should render with all named icons
     * without throwing.
     */
    public function test_audit_p12_3_empty_state_renders_all_named_icons(): void
    {
        $icons = ['gallery', 'artist', 'event', 'image', 'analytics', 'error', 'search'];

        foreach ($icons as $icon) {
            $rendered = view('components.empty-state', [
                'icon'        => $icon,
                'title'       => 'Test title',
                'description' => 'Test description',
            ])->render();

            $this->assertStringContainsString('role="status"', $rendered, "EmptyState icon={$icon} should have role=status");
            $this->assertStringContainsString('Test title', $rendered);
            $this->assertStringContainsString('Test description', $rendered);
            $this->assertStringContainsString('text-brand-400', $rendered, "EmptyState icon={$icon} should use brand color token");
        }
    }

    /**
     * AUDIT-P1-2.4: <x-tooltip> should render with role="tooltip" and
     * aria-describedby on the trigger.
     */
    public function test_audit_p12_4_tooltip_is_accessible(): void
    {
        $rendered = view('components.tooltip', [
            'text'     => 'Helpful tip',
            'position' => 'top',
        ])->render();

        $this->assertStringContainsString('role="tooltip"', $rendered, 'Tooltip element should have role=tooltip');
        $this->assertStringContainsString('aria-describedby=', $rendered, 'Trigger should have aria-describedby pointing at tooltip');
        $this->assertStringContainsString('Helpful tip', $rendered);
        $this->assertStringContainsString('x-data="{ open: false }"', $rendered, 'Tooltip should use Alpine for show/hide');
    }

    /**
     * AUDIT-P1-2.4: <x-tooltip> should accept all 4 position variants.
     */
    public function test_audit_p12_4_tooltip_supports_all_positions(): void
    {
        $positions = ['top', 'right', 'bottom', 'left'];
        $expectedClasses = [
            'top'    => 'bottom-full',
            'right'  => 'left-full',
            'bottom' => 'top-full',
            'left'   => 'right-full',
        ];

        foreach ($positions as $pos) {
            $rendered = view('components.tooltip', ['text' => 'x', 'position' => $pos])->render();
            $this->assertStringContainsString(
                $expectedClasses[$pos],
                $rendered,
                "Tooltip position={$pos} should use correct positioning class"
            );
        }
    }

    /**
     * AUDIT-P1-2.5: <x-toast> should render the toast container + the
     * window.toast function + the ARIA live region.
     */
    public function test_audit_p12_5_toast_component_renders_complete_system(): void
    {
        $rendered = view('components.toast')->render();

        // The container div
        $this->assertStringContainsString('id="toast-container"', $rendered);
        $this->assertStringContainsString('aria-live="polite"', $rendered);

        // The function definition
        $this->assertStringContainsString('window.toast = function', $rendered);
        $this->assertStringContainsString('nonce="', $rendered, 'Toast script must carry CSP nonce');

        // Auto-toast session calls (these render conditionally — no session = no call)
        // We just verify the conditional Blade syntax is present.
        $this->assertStringContainsString("session('success')", $rendered);
        $this->assertStringContainsString("session('error')", $rendered);
        $this->assertStringContainsString("session('info')", $rendered);
    }

    /**
     * AUDIT-P1-2.6: nav-link component should add aria-current="page" on
     * active links.
     */
    public function test_audit_p12_6_nav_link_adds_aria_current_when_active(): void
    {
        $activeRendered = view('components.nav-link', ['active' => true])->render();
        $this->assertStringContainsString('aria-current="page"', $activeRendered, 'Active nav-link should have aria-current=page');

        $inactiveRendered = view('components.nav-link', ['active' => false])->render();
        $this->assertStringNotContainsString('aria-current="page"', $inactiveRendered, 'Inactive nav-link should NOT have aria-current');
    }

    /**
     * AUDIT-P1-2.6: responsive-nav-link component should add aria-current.
     */
    public function test_audit_p12_6_responsive_nav_link_adds_aria_current_when_active(): void
    {
        $activeRendered = view('components.responsive-nav-link', ['active' => true])->render();
        $this->assertStringContainsString('aria-current="page"', $activeRendered);
    }
}

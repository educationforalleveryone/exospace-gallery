<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Iteration2Test extends TestCase
{
    use RefreshDatabase;

    public function test_audit_p12_7_proration_reads_prices_from_config(): void
    {
        $user = User::factory()->pro()->create([
            'plan_started_at'  => now()->subDays(15),
            'plan_expires_at'  => now()->addDays(15),
        ]);

        $service = app(ProrationService::class);
        $result = $service->calculateUpgradeCredit($user, 'studio');

        $this->assertEquals(29.0, (float) config('plans.display.pro.price'), 'Sanity: config pro price is 29');
        $this->assertEquals(99.0, (float) config('plans.display.studio.price'), 'Sanity: config studio price is 99');

        $this->assertEquals(14.5, $result['credit_amount'], 'Proration credit = 50% of Pro price', 0.01);
        $this->assertEquals(84.5, $result['new_price'], 'Adjusted Studio price = 99 - 14.5', 0.01);
        $this->assertStringContainsString('15 remaining days', $result['credit_description']);
    }

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

        $this->assertEquals(25.0, $result['credit_amount'], 'Proration should reflect runtime config override', 0.01);
        $this->assertEquals(175.0, $result['new_price'], 'Adjusted price should reflect runtime config override', 0.01);
    }

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

    public function test_audit_p12_2_skeleton_renders_all_variants(): void
    {
        $variants = ['text', 'row', 'card', 'chart', 'avatar', 'button'];

        foreach ($variants as $variant) {
            $rendered = view('components.skeleton', ['variant' => $variant])->render();
            $this->assertStringContainsString('animate-shimmer', $rendered, "Skeleton variant={$variant} should have animate-shimmer class");
            $this->assertStringContainsString('role="status"', $rendered, "Skeleton variant={$variant} should have role=status for screen readers");
        }
    }

    public function test_audit_p12_2_skeleton_count_renders_multiple(): void
    {
        $rendered = view('components.skeleton', ['variant' => 'row', 'count' => 5])->render();

        $shimmerCount = substr_count($rendered, 'animate-shimmer');
        $this->assertEquals(5, $shimmerCount, 'Skeleton with count=5 should render 5 shimmer divs');
    }

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

    public function test_audit_p12_5_toast_component_renders_complete_system(): void
    {
        $rendered = view('components.toast')->render();

        // The container div
        $this->assertStringContainsString('id="toast-container"', $rendered);
        $this->assertStringContainsString('aria-live="polite"', $rendered);

        $this->assertStringContainsString('window.toast = function', $rendered);
        $this->assertStringContainsString('nonce="', $rendered, 'Toast script must carry CSP nonce');

        session(['success' => 'Gallery saved']);
        $flashRendered = view('components.toast')->render();
        $this->assertStringContainsString('toast("Gallery saved", \'success\')', $flashRendered,
            'A session flash must fire a toast on load');

        session()->forget('success');
        session(['error' => 'Something broke']);
        $this->assertStringContainsString('toast("Something broke", \'error\')',
            view('components.toast')->render());
    }

    public function test_audit_p12_6_nav_link_adds_aria_current_when_active(): void
    {
        $activeRendered = view('components.nav-link', ['active' => true])->render();
        $this->assertStringContainsString('aria-current="page"', $activeRendered, 'Active nav-link should have aria-current=page');

        $inactiveRendered = view('components.nav-link', ['active' => false])->render();
        $this->assertStringNotContainsString('aria-current="page"', $inactiveRendered, 'Inactive nav-link should NOT have aria-current');
    }

    public function test_audit_p12_6_responsive_nav_link_adds_aria_current_when_active(): void
    {
        $activeRendered = view('components.responsive-nav-link', ['active' => true])->render();
        $this->assertStringContainsString('aria-current="page"', $activeRendered);
    }
}

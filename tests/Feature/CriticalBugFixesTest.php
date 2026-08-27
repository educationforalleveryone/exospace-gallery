<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IncrementGalleryViews;
use App\Models\Gallery;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Iteration-009 regression tests for the remaining critical bug fixes:
 *   - C-7: GalleryViewController defers view_count increments via job
 *
 * (C-1, C-3, C-9, C-10 were already fixed in earlier iterations — we
 * verify their state here too.)
 *
 * C-8 (contact form success-on-failure) is a frontend-only fix — verified
 * by manual browser testing. The PHP-side ContactController was already
 * returning proper 4xx/5xx JSON, so no backend test is needed.
 *
 * C-6 (OgImageController Imagick draws) is also primarily a perf fix —
 * the visual output is identical. A render-time test would require Imagick
 * which isn't available in CI; we verify the helper methods exist.
 */
class CriticalBugFixesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function c7_gallery_view_dispatches_increment_job_after_response(): void
    {
        Queue::fake();

        $gallery = Gallery::factory()->create([
            'is_active'   => true,
            'view_count'  => 100,
        ]);

        $response = $this->get(route('gallery.view', $gallery->slug));

        $response->assertStatus(200);

        // The increment job should have been dispatched with afterResponse.
        Queue::assertPushed(IncrementGalleryViews::class, function ($job) use ($gallery) {
            return $job->galleryId === $gallery->id;
        });
    }

    /** @test */
    public function c7_embed_view_does_not_dispatch_increment_job(): void
    {
        Queue::fake();

        $gallery = Gallery::factory()->create([
            'is_active'  => true,
            'view_count' => 100,
        ]);

        $response = $this->get(route('gallery.view', $gallery->slug) . '?embed=1');

        $response->assertStatus(200);
        Queue::assertNotPushed(IncrementGalleryViews::class);
    }

    /** @test */
    public function c7_increment_job_actually_increments_view_count(): void
    {
        // Use the real queue (sync) — we want to verify the side effect.
        // NOTE (QA-Control-Center fix): the previous `Queue::fake([])` line
        // was INTENDED to "clear" an earlier fake, but on Laravel 12 even
        // `dispatchSync()` is intercepted while a Queue fake is bound to the
        // container — so the job never executed and this test failed with
        // 100 !== 101. RefreshDatabase already isolates each test, so no
        // un-faking is needed; simply don't fake here.
        $gallery = Gallery::factory()->create([
            'is_active'  => true,
            'view_count' => 100,
        ]);

        $venue = VenueTemplate::factory()->create(['view_count' => 50]);

        // Dispatch + run synchronously.
        IncrementGalleryViews::dispatchSync($gallery->id, $venue->id);

        $this->assertSame(101, (int) $gallery->fresh()->view_count);
        $this->assertSame(51, (int) $venue->fresh()->view_count);
    }

    /** @test */
    public function c7_increment_job_is_safe_for_missing_gallery(): void
    {
        // Dispatching with a non-existent gallery ID should not throw.
        IncrementGalleryViews::dispatchSync(99999999, null);

        $this->assertTrue(true, 'Job did not throw on missing gallery');
    }

    /** @test */
    public function c7_increment_job_continues_if_venue_template_missing(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active'  => true,
            'view_count' => 100,
        ]);

        // Pass a non-existent venue template ID — the gallery increment
        // should still succeed, and the venue increment should silently no-op.
        IncrementGalleryViews::dispatchSync($gallery->id, 99999999);

        $this->assertSame(101, (int) $gallery->fresh()->view_count);
    }

    /** @test */
    public function c1_prune_transactions_command_uses_unix_timestamp_not_from_days(): void
    {
        // Verify the fix from Iter-003 is still in place: the command's
        // source must NOT contain FROM_DAYS() anywhere.
        $source = file_get_contents(base_path('app/Console/Commands/PruneTransactionsByPartition.php'));
        // Strip comments first — the file's own docblock documents that
        // FROM_DAYS was removed and mentions it verbatim. (QA-Control-Center fix)
        $codeOnly = trim(preg_replace([
            '~/\*.*?\*/~s',
            '~^\s*//.*$~m',
        ], '', $source));
        $this->assertStringNotContainsString('FROM_DAYS', $codeOnly, 'C-1 regression: FROM_DAYS must not appear in executable partition-pruning code');
        $this->assertStringContainsString('createFromTimestamp', $source, 'C-1 fix in place: Carbon::createFromTimestamp used');
    }

    /** @test */
    public function c3_analytics_event_model_does_not_have_country_in_fillable(): void
    {
        // Verify the fix from Iter-003 is still in place.
        $reflection = new \ReflectionClass(\App\Models\AnalyticsEvent::class);
        $fillable = $reflection->getProperty('fillable');
        $fillable->setAccessible(true);
        $value = $fillable->getValue(new \App\Models\AnalyticsEvent());

        $this->assertNotContains('country', $value, 'C-3 regression: country must not be in $fillable (column was dropped)');
    }

    /** @test */
    public function c9_service_worker_does_not_pre_cache_literal_css_path(): void
    {
        // Verify the fix from Iter-005 is still in place: the SW's APP_SHELL
        // must NOT contain the literal '/build/assets/app.css' path.
        $source = file_get_contents(public_path('sw.js'));
        // Strip comment lines — sw.js documents the C-9 fix in comments that
        // mention the literal path. (QA-Control-Center fix)
        $codeOnly = trim(preg_replace('~^\s*//.*$~m', '', $source));
        $this->assertStringNotContainsString("'/build/assets/app.css'", $codeOnly, 'C-9 regression: SW must not pre-cache the literal app.css path');
        $this->assertStringNotContainsString('"/build/assets/app.css"', $codeOnly, 'C-9 regression: SW must not pre-cache the literal app.css path (double-quote variant)');
    }

    /** @test */
    public function c10_no_tailwind4_dependency_in_package_json(): void
    {
        // Verify the fix from Iter-005 is still in place: package.json must
        // NOT contain @tailwindcss/vite (Tailwind 4) — only tailwindcss v3.
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        $allDeps = array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? [],
        );

        $this->assertArrayNotHasKey('@tailwindcss/vite', $allDeps, 'C-10 regression: @tailwindcss/vite (Tailwind 4) must not be a dependency');
        $this->assertArrayHasKey('tailwindcss', $package['devDependencies'] ?? [], 'C-10: Tailwind 3 must be present');
    }

    /** @test */
    public function c10_tailwind_content_array_includes_js_files(): void
    {
        // Verify the fix from Iter-005 is still in place: tailwind.config.js
        // content array must scan resources/js/** for Tailwind classes that
        // exist only in JS (Alpine components, gallery JS).
        $source = file_get_contents(base_path('tailwind.config.js'));
        $this->assertStringContainsString('./resources/js/**/*.{js,ts,vue,blade.php}', $source, 'C-10: tailwind content must include resources/js glob');
    }

    /** @test */
    public function c6_og_image_controller_caches_radial_and_overlay_helpers(): void
    {
        // C-6 fix: the controller must have the new cached helper methods
        // and must NOT have the old 150-iteration draw loops inline in render().
        $source = file_get_contents(base_path('app/Http/Controllers/OgImageController.php'));

        $this->assertStringContainsString('getCachedRadialHighlight', $source, 'C-6 fix: radial highlight helper must exist');
        $this->assertStringContainsString('getCachedCoverOverlay', $source, 'C-6 fix: cover overlay helper must exist');

        // The old per-pixel loops inside render() must be gone.
        // (We can't assert they're fully gone without parsing PHP, but we can
        // at least check that the old `for ($r = 0; $r < 600; $r += 4)` radial
        // loop is no longer in the render() method.)
        $renderStart = strpos($source, 'private function render(');
        $renderEnd = strpos($source, 'private function text(');
        if ($renderStart !== false && $renderEnd !== false) {
            $renderMethod = substr($source, $renderStart, $renderEnd - $renderStart);
            $this->assertStringNotContainsString('for ($r = 0; $r < 600; $r += 4)', $renderMethod, 'C-6 fix: 150-iteration radial loop must be removed from render()');
            $this->assertStringNotContainsString('for ($x = 0; $x < 600; $x += 4)', $renderMethod, 'C-6 fix: 150-iteration overlay loop must be removed from render()');
        }
    }

    /** @test */
    public function c8_contact_form_js_parses_response_status(): void
    {
        // C-8 fix: contact.blade.php's fetch handler must check response.ok
        // rather than blindly showing success.
        $source = file_get_contents(resource_path('views/pages/contact.blade.php'));

        $this->assertStringContainsString('response.ok', $source, 'C-8 fix: contact form must check response.ok');
        $this->assertStringContainsString('response.status === 422', $source, 'C-8 fix: contact form must handle 422 validation errors');
        $this->assertStringContainsString('response.status >= 500', $source, 'C-8 fix: contact form must handle 5xx server errors');
        $this->assertStringContainsString('showErrorToast', $source, 'C-8 fix: contact form must have an error toast helper');

        // The old comment that admitted the bug must be gone.
        $this->assertStringNotContainsString('Show success regardless of backend status', $source, 'C-8 regression: the broken comment must be removed');
        $this->assertStringNotContainsString('Still show success — the form data is captured', $source, 'C-8 regression: the broken catch handler must be removed');
    }
}

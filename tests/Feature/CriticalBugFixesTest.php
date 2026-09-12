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

class CriticalBugFixesTest extends TestCase
{
    use RefreshDatabase;

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

    public function c7_increment_job_actually_increments_view_count(): void
    {
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

    public function c7_increment_job_is_safe_for_missing_gallery(): void
    {
        // Dispatching with a non-existent gallery ID should not throw.
        IncrementGalleryViews::dispatchSync(99999999, null);

        $this->assertTrue(true, 'Job did not throw on missing gallery');
    }

    public function c7_increment_job_continues_if_venue_template_missing(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active'  => true,
            'view_count' => 100,
        ]);

        IncrementGalleryViews::dispatchSync($gallery->id, 99999999);

        $this->assertSame(101, (int) $gallery->fresh()->view_count);
    }

    public function c1_prune_transactions_command_uses_unix_timestamp_not_from_days(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/PruneTransactionsByPartition.php'));
        $codeOnly = trim(preg_replace([
            '~/\*.*?\*/~s',
            '~^\s*//.*$~m',
        ], '', $source));
        $this->assertStringNotContainsString('FROM_DAYS', $codeOnly, 'C-1 regression: FROM_DAYS must not appear in executable partition-pruning code');
        $this->assertStringContainsString('createFromTimestamp', $source, 'C-1 fix in place: Carbon::createFromTimestamp used');
    }

    public function c3_analytics_event_model_does_not_have_country_in_fillable(): void
    {
        // Verify the fix from Iter-003 is still in place.
        $reflection = new \ReflectionClass(\App\Models\AnalyticsEvent::class);
        $fillable = $reflection->getProperty('fillable');
        $fillable->setAccessible(true);
        $value = $fillable->getValue(new \App\Models\AnalyticsEvent());

        $this->assertNotContains('country', $value, 'C-3 regression: country must not be in $fillable (column was dropped)');
    }

    public function c9_service_worker_does_not_pre_cache_literal_css_path(): void
    {
        $source = file_get_contents(public_path('sw.js'));
        $codeOnly = trim(preg_replace('~^\s*//.*$~m', '', $source));
        $this->assertStringNotContainsString("'/build/assets/app.css'", $codeOnly, 'C-9 regression: SW must not pre-cache the literal app.css path');
        $this->assertStringNotContainsString('"/build/assets/app.css"', $codeOnly, 'C-9 regression: SW must not pre-cache the literal app.css path (double-quote variant)');
    }

    public function c10_no_tailwind4_dependency_in_package_json(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        $allDeps = array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? [],
        );

        $this->assertArrayNotHasKey('@tailwindcss/vite', $allDeps, 'C-10 regression: @tailwindcss/vite (Tailwind 4) must not be a dependency');
        $this->assertArrayHasKey('tailwindcss', $package['devDependencies'] ?? [], 'C-10: Tailwind 3 must be present');
    }

    public function c10_tailwind_content_array_includes_js_files(): void
    {
        $source = file_get_contents(base_path('tailwind.config.js'));
        $this->assertStringContainsString('./resources/js/**/*.{js,ts,vue,blade.php}', $source, 'C-10: tailwind content must include resources/js glob');
    }

    public function c6_og_image_controller_caches_radial_and_overlay_helpers(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/OgImageController.php'));

        $this->assertStringContainsString('getCachedRadialHighlight', $source, 'C-6 fix: radial highlight helper must exist');
        $this->assertStringContainsString('getCachedCoverOverlay', $source, 'C-6 fix: cover overlay helper must exist');

        $renderStart = strpos($source, 'private function render(');
        $renderEnd = strpos($source, 'private function text(');
        if ($renderStart !== false && $renderEnd !== false) {
            $renderMethod = substr($source, $renderStart, $renderEnd - $renderStart);
            $this->assertStringNotContainsString('for ($r = 0; $r < 600; $r += 4)', $renderMethod, 'C-6 fix: 150-iteration radial loop must be removed from render()');
            $this->assertStringNotContainsString('for ($x = 0; $x < 600; $x += 4)', $renderMethod, 'C-6 fix: 150-iteration overlay loop must be removed from render()');
        }
    }

    public function c8_contact_form_js_parses_response_status(): void
    {
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

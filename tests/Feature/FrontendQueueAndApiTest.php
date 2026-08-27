<?php

declare(strict_types=1);

/**
 * Iteration-005 regression tests for D-5 (Sanctum abilities), C-4 (SendWelcomeEmail),
 * C-5 (ProcessPlanDowngrade backoff), K-1 (imagick drift), K-5/K-9 (schedules).
 *
 * Run: php artisan test --filter=FrontendQueueAndApiTest
 */

namespace Tests\Feature;

use App\Jobs\ProcessPlanDowngrade;
use App\Listeners\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class FrontendQueueAndApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_d5_api_read_endpoints_have_ability_read_middleware(): void
    {
        // D-5 FIX: read endpoints should have 'ability:read' middleware
        $readRoutes = ['api.v1.tokens.index', 'api.v1.me', 'api.v1.me.galleries'];
        // Note: route names may not be defined — check by URL instead
        $routes = Route::getRoutes();

        $readEndpoints = [
            'GET /api/v1/tokens',
            'GET /api/v1/me',
            'GET /api/v1/me/galleries',
        ];

        // ITERATION-1 FIX: the old lookup (a) concatenated method+URI without
        // a separator so RouteCollection::get() never resolved anything, and
        // (b) compared lowercased methods against the UPPERCASE values that
        // Route::methods() returns — the fallback loop never matched either,
        // so this test never actually asserted anything. Match case-blind.
        foreach ($readEndpoints as $endpoint) {
            [$method, $uri] = explode(' ', $endpoint);
            $uri = ltrim($uri, '/');
            $matched = false;
            foreach ($routes as $r) {
                if ($r->uri() === $uri
                    && in_array(strtoupper($method), array_map('strtoupper', $r->methods()), true)) {
                    $this->assertContains('ability:read', $r->gatherMiddleware(),
                        "D-5: {$endpoint} must have 'ability:read' middleware.");
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, "D-5: Route not found for {$endpoint}");
        }
    }

    public function test_d5_api_write_endpoints_have_ability_write_middleware(): void
    {
        // D-5 FIX: write endpoints should have 'ability:write' middleware
        $writeEndpoints = [
            'POST /api/v1/tokens',
            'DELETE /api/v1/tokens/{tokenId}',
        ];

        $routes = Route::getRoutes();

        foreach ($writeEndpoints as $endpoint) {
            [$method, $uri] = explode(' ', $endpoint);
            $uri = ltrim($uri, '/');
            // Replace {tokenId} with regex pattern for matching
            $uriPattern = preg_replace('/\{[^}]+\}/', '*', $uri);

            // ITERATION-1 FIX: same case-blind method comparison as the
            // read-endpoint test (methods() returns UPPERCASE).
            $matched = false;
            foreach ($routes as $r) {
                if (in_array(strtoupper($method), array_map('strtoupper', $r->methods()), true)
                    && fnmatch($uriPattern, $r->uri())) {
                    $this->assertContains('ability:write', $r->gatherMiddleware(),
                        "D-5: {$endpoint} must have 'ability:write' middleware.");
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, "D-5: Route not found for {$endpoint}");
        }
    }

    public function test_d5_read_token_cannot_access_write_endpoints(): void
    {
        // D-5 FIX: a read-only token should get 403 on POST /api/v1/tokens
        $user = User::factory()->create();
        $token = $user->createToken('test-read', ['read']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/tokens', [
                'name' => 'should-fail',
                'abilities' => ['write'],
            ]);

        $response->assertStatus(403);
    }

    public function test_d5_write_token_can_access_write_endpoints(): void
    {
        // D-5 FIX: a write token should be able to POST /api/v1/tokens
        $user = User::factory()->create();
        $token = $user->createToken('test-write', ['read', 'write']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/tokens', [
                'name' => 'should-succeed',
                'abilities' => ['read'],
            ]);

        $response->assertStatus(201);
    }

    public function test_c4_send_welcome_email_does_not_use_session(): void
    {
        // C-4 FIX: the SendWelcomeEmail listener should NOT call session()
        // (it runs on the queue worker, where session is not available)
        $listenerFile = file_get_contents(app_path('Listeners/SendWelcomeEmail.php'));

        // Strip comments before scanning: the file's own DOCBLOCK explains
        // the C-4 fix and legitimately contains the words "session()" in
        // prose. We only care about executable code. (QA-Control-Center fix)
        $codeWithoutComments = trim(preg_replace([
            '~/\*.*?\*/~s',      // block comments / docblocks
            '~^\s*//.*$~m',      // line comments
        ], '', $listenerFile));

        $this->assertStringNotContainsString('session(', $codeWithoutComments,
            'C-4: SendWelcomeEmail must NOT use session() — it runs on the queue worker where session is unavailable.');
        $this->assertStringContainsString('hasVerifiedEmail', $listenerFile,
            'C-4: SendWelcomeEmail should use hasVerifiedEmail() instead of session().');
    }

    public function test_c5_process_plan_downgrade_backoff_is_array(): void
    {
        // C-5 FIX: the backoff should be an array [60, 180, 540], not int 60
        $reflection = new \ReflectionClass(ProcessPlanDowngrade::class);
        $backoffProp = $reflection->getProperty('backoff');
        $backoffProp->setAccessible(true);

        $job = new \ReflectionClass(ProcessPlanDowngrade::class);
        $defaultProperties = $job->getDefaultProperties();

        $this->assertIsArray($defaultProperties['backoff'],
            'C-5: ProcessPlanDowngrade::$backoff should be an array.');
        $this->assertEquals([60, 180, 540], $defaultProperties['backoff'],
            'C-5: ProcessPlanDowngrade::$backoff should be [60, 180, 540] (exponential).');
    }

    public function test_k1_image_processing_service_does_not_use_imagick(): void
    {
        // K-1 FIX: ImageProcessingService should NOT reference Imagick
        $serviceFile = file_get_contents(app_path('Services/ImageProcessingService.php'));
        $this->assertStringNotContainsString('ImagickDriver', $serviceFile,
            'K-1: ImageProcessingService should NOT reference ImagickDriver (production doesn\'t have imagick).');
        $this->assertStringNotContainsString('extension_loaded(\'imagick\')', $serviceFile,
            'K-1: ImageProcessingService should NOT check for imagick extension (commit to GD-only).');
        $this->assertStringContainsString('GdDriver', $serviceFile,
            'K-1: ImageProcessingService should use GdDriver.');
    }

    public function test_k1_ci_does_not_install_imagick(): void
    {
        // K-1 FIX: CI workflow should NOT install imagick
        $ciFile = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertStringNotContainsString('imagick', $ciFile,
            'K-1: CI workflow should NOT install imagick (production doesn\'t have it).');
    }

    public function test_c9_service_worker_does_not_cache_literal_css_path(): void
    {
        // C-9 FIX: the service worker should NOT pre-cache /build/assets/app.css
        $swFile = file_get_contents(public_path('sw.js'));

        // Strip // comment lines first: sw.js documents the C-9 fix in a
        // comment that mentions the literal path. Only real code lines may
        // trip this assertion. (QA-Control-Center fix)
        $codeWithoutComments = trim(preg_replace('~^\s*//.*$~m', '', $swFile));

        $this->assertStringNotContainsString("'/build/assets/app.css'", $codeWithoutComments,
            'C-9: Service worker should NOT pre-cache /build/assets/app.css (it 404s — the real CSS is content-hashed).');
    }

    public function test_c10_tailwind_config_includes_js_files(): void
    {
        // C-10 FIX: tailwind.config.js content array should include JS files
        $configFile = file_get_contents(base_path('tailwind.config.js'));
        $this->assertStringContainsString('resources/js', $configFile,
            'C-10: tailwind.config.js content array should include resources/js for CSS purging.');
    }

    public function test_c10_package_json_does_not_have_tailwind_vite_plugin(): void
    {
        // C-10 FIX: @tailwindcss/vite should be removed (dead dependency, Tailwind 4)
        $packageJson = file_get_contents(base_path('package.json'));
        $this->assertStringNotContainsString('@tailwindcss/vite', $packageJson,
            'C-10: package.json should NOT have @tailwindcss/vite (dead dep — Tailwind 4, not wired into Vite).');
    }

    /**
     * ITERATION-1 FIX: Schedule::assertScheduled() does not exist in
     * Laravel 11/12 (the old tests called an imaginary API and errored).
     * Inspect the real scheduler's events instead.
     */
    private function scheduledCommands(): array
    {
        return collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => trim((string) $event->command))
            ->all();
    }

    public function test_k5_queue_prune_failed_is_scheduled(): void
    {
        // K-5 FIX: queue:prune-failed should be in the schedule
        $this->assertTrue(
            collect($this->scheduledCommands())->contains(
                fn ($cmd) => str_contains($cmd, 'queue:prune-failed') && str_contains($cmd, '--hours=168'),
            ),
            'queue:prune-failed --hours=168 must be scheduled daily',
        );
    }

    public function test_k9_cohort_retention_is_scheduled(): void
    {
        // K-9 FIX: exospace:cohort-retention should be in the schedule
        $this->assertTrue(
            collect($this->scheduledCommands())->contains(
                fn ($cmd) => str_contains($cmd, 'exospace:cohort-retention'),
            ),
            'exospace:cohort-retention must be scheduled weekly',
        );
    }

    public function test_k9_onboarding_analytics_is_scheduled(): void
    {
        // K-9 FIX: exospace:onboarding-analytics should be in the schedule
        $this->assertTrue(
            collect($this->scheduledCommands())->contains(
                fn ($cmd) => str_contains($cmd, 'exospace:onboarding-analytics'),
            ),
            'exospace:onboarding-analytics must be scheduled weekly',
        );
    }

    /**
     * AUDIT-P0-1.7 FIX: GalleryApiController::formatImage previously referenced
     * `$img->thumbnail` which is neither a column nor an accessor on
     * GalleryImage — so thumbnail_url was always null. Now uses
     * GalleryImage::conversionUrl('thumb') with a fallback to the original
     * asset URL when no Spatie media conversion exists.
     *
     * This test verifies that thumbnail_url is non-null in API responses
     * for galleries with at least one image.
     */
    public function test_audit_p01_7_gallery_api_returns_non_null_thumbnail_url(): void
    {
        $user = User::factory()->create();
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'  => $user->id,
            'is_active' => true,
            'pin_hash'  => null,
            'opens_at'  => null,
            'closes_at' => null,
        ]);
        \App\Models\GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'path'       => 'galleries/' . $gallery->id . '/test.jpg',
        ]);

        // Hit the API endpoint (no auth needed for publicly-viewable galleries).
        $response = $this->getJson("/api/v1/galleries/{$gallery->slug}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['id', 'title', 'slug'],
            'images' => [
                ['id', 'url', 'thumbnail_url'],
            ],
        ]);

        $firstImage = $response->json('images.0');
        $this->assertNotNull(
            $firstImage['thumbnail_url'],
            'AUDIT-P0-1.7: thumbnail_url should be non-null after the fix. '
            . 'Either Spatie media conversion resolved, or the original asset URL fallback was used.'
        );
        $this->assertStringStartsWith(
            'http',
            $firstImage['thumbnail_url'],
            'AUDIT-P0-1.7: thumbnail_url should be an absolute URL.'
        );
    }
}

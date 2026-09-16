<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
    }

    // ── CSP: required directives ─────────────────────────────────────────

    public function test_production_csp_declares_all_required_directives(): void
    {
        $csp = $this->cspFor('/');

        foreach ([
            "default-src 'self'",
            'script-src',
            'style-src',
            'img-src',
            'font-src',
            'media-src',
            'connect-src',
            'worker-src',
            'frame-src',
            'frame-ancestors',
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $csp, "CSP must declare [{$fragment}].");
        }

        $this->assertStringContainsString("'nonce-", $csp, 'script-src must carry the per-request nonce.');
        $this->assertStringContainsString("'strict-dynamic'", $csp);
    }

    public function test_production_csp_contains_no_development_allowances(): void
    {
        $csp = $this->cspFor('/');

        foreach (['localhost', '127.0.0.1', ':5173', 'ws://', 'hot'] as $devArtifact) {
            $this->assertStringNotContainsString($devArtifact, $csp, "CSP must not contain the dev artifact [{$devArtifact}].");
        }
    }

    // ── CSP: 3D viewer compatibility ─────────────────────────────────────

    public function test_csp_permits_the_viewers_legitimate_resource_requirements(): void
    {
        $csp = $this->cspFor('/');

        // GLB-embedded textures are fetched from blob: object URLs (ImageBitmapLoader)
        preg_match('/connect-src[^;]*/', $csp, $connect);
        $this->assertStringContainsString('blob:', $connect[0] ?? '');

        // Texture loading paths use data:/blob: image sources
        preg_match('/img-src[^;]*/', $csp, $img);
        $this->assertStringContainsString('blob:', $img[0] ?? '');
        $this->assertStringContainsString('data:', $img[0] ?? '');

        // KTX2 transcoder builds its workers from blob: URLs
        preg_match('/worker-src[^;]*/', $csp, $worker);
        $this->assertStringContainsString("'self'", $worker[0] ?? '');
        $this->assertStringContainsString('blob:', $worker[0] ?? '');

        // Draco/Basis WASM decoders compile via eval-family compilation,
        // covered alongside Alpine by 'unsafe-eval' in script-src.
        $this->assertStringContainsString("'unsafe-eval'", $csp);

        // Ambient exhibition audio ships as blob: or same-origin media
        preg_match('/media-src[^;]*/', $csp, $media);
        $this->assertStringContainsString('blob:', $media[0] ?? '');
    }

    public function test_csp_frame_src_permits_the_turnstile_widget(): void
    {
        $csp = $this->cspFor('/');

        preg_match('/frame-src[^;]*/', $csp, $frame);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $frame[0] ?? '',
            'The Turnstile widget renders in a challenges.cloudflare.com iframe.');
    }

    // ── CSP: script channel integrity ────────────────────────────────────

    public function test_vite_entry_scripts_carry_the_request_nonce(): void
    {
        $response = $this->get('/');
        $nonce = request()->attributes->get('csp_nonce');

        $this->assertNotEmpty($nonce, 'Middleware must generate a per-request nonce.');
        $this->assertSame($nonce, Vite::cspNonce(),
            'The nonce must be registered on the Vite instance so @vite tags carry it.');

        $html = $response->getContent();
        $this->assertNotNull($html);

        // Every module script tag referencing the build output must be nonce'd —
        // under 'strict-dynamic' browsers ignore 'self' for script-src, so an
        // un-nonce'd bundle would be blocked outright.
        preg_match_all('/<script[^>]*type="module"[^>]*>/i', $html, $tags);
        $this->assertNotEmpty($tags[0], 'The page must render module script tags for the Vite build.');

        foreach ($tags[0] as $tag) {
            $referencesBuild = str_contains($tag, '/build/');
            if (! $referencesBuild) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag,
                'Vite entry script tags must carry the request nonce: '.$tag);
        }
    }

    // ── HSTS ─────────────────────────────────────────────────────────────

    public function test_hsts_is_not_sent_on_insecure_responses(): void
    {
        $request = Request::create('http://exospace.gallery/');

        $response = $this->runMiddleware($request);

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_on_secure_responses_is_max_age_only(): void
    {
        $request = Request::create('https://exospace.gallery/');

        $response = $this->runMiddleware($request);

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=31536000', $hsts,
            'HSTS must be max-age=31536000 exactly — no preload, no includeSubDomains.');
    }

    public function test_hsts_is_not_sent_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $request = Request::create('https://exospace.gallery/');

        $response = $this->runMiddleware($request);

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    // ── Frame / embed policy ─────────────────────────────────────────────

    public function test_public_exhibition_is_embeddable_anywhere_without_xfo(): void
    {
        $gallery = $this->makeGallery();

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match('/frame-ancestors[^;]*/', $csp, $frame);
        $this->assertSame('frame-ancestors *', trim($frame[0] ?? ''),
            'The public exhibition page must remain embeddable (embed snippet feature).');
        $this->assertNull($response->headers->get('X-Frame-Options'),
            'X-Frame-Options cannot express allow-all and must be omitted on embeddable pages.');
    }

    public function test_same_origin_preview_routes_allow_only_self_framing(): void
    {
        [$csp, $xfo] = $this->framePolicyForRouteNamed('venues.preview');
        preg_match('/frame-ancestors[^;]*/', $csp, $frame);
        $this->assertSame("frame-ancestors 'self'", trim($frame[0] ?? ''));
        $this->assertSame('SAMEORIGIN', $xfo);

        [$csp, $xfo] = $this->framePolicyForRouteNamed('admin.galleries.preview');
        preg_match('/frame-ancestors[^;]*/', $csp, $frame);
        $this->assertSame("frame-ancestors 'self'", trim($frame[0] ?? ''));
        $this->assertSame('SAMEORIGIN', $xfo);
    }

    public function test_all_other_pages_refuse_framing(): void
    {
        $response = $this->get('/');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        preg_match('/frame-ancestors[^;]*/', $csp, $frame);
        $this->assertSame("frame-ancestors 'none'", trim($frame[0] ?? ''),
            'Non-exhibition pages must deny framing outright.');
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));

        // JSON API responses must not become frameable documents either.
        $api = $this->getJson('/api/v1/galleries');
        preg_match('/frame-ancestors[^;]*/', (string) $api->headers->get('Content-Security-Policy'), $apiFrame);
        $this->assertSame("frame-ancestors 'none'", trim($apiFrame[0] ?? ''));
        $this->assertSame('DENY', $api->headers->get('X-Frame-Options'));
    }

    // ── Core headers on every response class ─────────────────────────────

    public function test_core_headers_are_present_on_html_and_json_responses(): void
    {
        foreach ([$this->get('/'), $this->getJson('/api/v1/galleries')] as $response) {
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
            $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
        }
    }

    // ── Permissions policy ───────────────────────────────────────────────

    public function test_permissions_policy_closes_unused_capabilities_and_keeps_viewer_ones(): void
    {
        $policy = (string) $this->get('/')->headers->get('Permissions-Policy');

        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'gyroscope=()', 'accelerometer=()'] as $denied) {
            $this->assertStringContainsString($denied, $policy, "Unused capability [{$denied}] must be denied.");
        }

        $this->assertStringContainsString('fullscreen=(self)', $policy,
            'The viewer uses fullscreen; it must stay granted to self.');
        $this->assertStringContainsString('autoplay=(self)', $policy,
            'The viewer autoplays ambient audio; it must stay granted to self.');

        // Checkout is a top-level redirect to 2Checkout's hosted page — the
        // Payment Request API is never delegated to their origin.
        $this->assertStringNotContainsString('2checkout.com', $policy);
    }

    // ── Cache / privacy ──────────────────────────────────────────────────

    public function test_authenticated_html_is_not_publicly_cacheable(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/');

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $cacheControl,
            'Authenticated HTML must not be stored by shared caches or the browser cache.');
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_guest_html_is_marked_private_never_public(): void
    {
        $response = $this->get('/');

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl,
            'HTML documents must never be marked publicly cacheable.');
    }

    // ── Local development ────────────────────────────────────────────────

    public function test_csp_is_absent_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'),
            'Local development must run without CSP (Vite HMR uses dev origins).');
    }

    // ── Turnstile bootstrap tags ─────────────────────────────────────────

    public function test_turnstile_script_tags_carry_the_nonce(): void
    {
        foreach ([
            resource_path('views/gallery/view.blade.php'),
            resource_path('views/gallery/events.blade.php'),
            resource_path('views/pages/contact.blade.php'),
        ] as $view) {
            $source = (string) file_get_contents($view);

            $this->assertStringContainsString(
                '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" nonce="@nonce" async defer></script>',
                $source,
                "{$view}: the Turnstile bootstrap script needs the nonce — 'strict-dynamic' blocks un-nonce'd scripts."
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function cspFor(string $uri): string
    {
        $response = $this->get($uri);
        $response->assertOk();

        return (string) $response->headers->get('Content-Security-Policy');
    }

    private function runMiddleware(Request $request): Response
    {
        return (new SecurityHeaders)->handle(
            $request,
            fn () => new Response('<html></html>', 200, ['Content-Type' => 'text/html'])
        );
    }

    /**
     * Run the frame policy for a route class without exercising its auth stack:
     * the middleware only reads the matched route's name.
     *
     * @return array{0: string, 1: string|null} [CSP header, X-Frame-Options header]
     */
    private function framePolicyForRouteNamed(string $routeName): array
    {
        $route = new \Illuminate\Routing\Route('GET', '/framed', fn () => null);
        $route->name($routeName);

        $request = Request::create('https://exospace.gallery/framed');
        $request->setRouteResolver(fn () => $route);

        $response = $this->runMiddleware($request);

        return [
            (string) $response->headers->get('Content-Security-Policy'),
            $response->headers->get('X-Frame-Options'),
        ];
    }

    private function makeGallery(array $attrs = []): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Coastal Light',
            'slug' => 'coastal-light-'.uniqid(),
            'description' => 'A photographic survey of shoreline towns.',
            'is_active' => true,
        ], $attrs));
    }
}

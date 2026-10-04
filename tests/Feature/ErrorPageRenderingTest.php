<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErrorPageRenderingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BRAND_BACKGROUND = '#0f1117';

    protected function tearDown(): void
    {
        if (file_exists(storage_path('framework/down'))) {
            @unlink(storage_path('framework/down'));
        }

        parent::tearDown();
    }

    #[Test]
    public function not_found_renders_branded_page(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('404');
        $response->assertSee(__('Page not found'));
        $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
        $this->assertStringContainsString('noindex', $response->getContent());
    }

    #[Test]
    public function forbidden_renders_branded_page(): void
    {
        $response = $this->renderException(new AccessDeniedHttpException('Policy denial.'));

        $response->assertStatus(403);
        $response->assertSee('403');
        $response->assertSee(__('Access denied'));
        $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
        $this->assertStringNotContainsString('Policy denial.', $response->getContent());
    }

    #[Test]
    public function csrf_expiration_renders_branded_419_page(): void
    {
        // Laravel's CSRF middleware is a no-op under runningUnitTests();
        // flipping the env mirrors the pattern used by CsrfCrossOriginBoundaryTest.
        $this->app['env'] = 'production';

        $response = $this->post(route('contact.submit'), ['name' => 'x', 'email' => 'x', 'message' => 'x'], [
            'X-CSRF-TOKEN' => 'stale-token',
        ]);

        $response->assertStatus(419);
        $response->assertSee('419');
        $response->assertSee(__('Page Expired'));
        $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
    }

    #[Test]
    public function rate_limit_renders_branded_429_page(): void
    {
        $response = $this->renderException(new ThrottleRequestsException('Too Many Attempts.'));

        $response->assertStatus(429);
        $response->assertSee('429');
        $response->assertSee(__('Too Many Requests'));
        $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
        $this->assertStringNotContainsString('Too Many Attempts.', $response->getContent());
    }

    #[Test]
    public function server_error_renders_branded_page_without_leaking_exception_details(): void
    {
        $secret = 'redis://internal:6379/db-secret-'.__LINE__;

        $response = $this->renderException(new \RuntimeException($secret));

        $response->assertStatus(500);
        $response->assertSee('500');
        $response->assertSee(__('Something went wrong'));
        $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('vendor/', $response->getContent());
    }

    #[Test]
    public function maintenance_mode_renders_branded_503_page(): void
    {
        $this->artisan('down')->assertSuccessful();

        try {
            $response = $this->get('/');

            $response->assertStatus(503);
            $response->assertSee('503');
            $response->assertSee(__('Temporarily Unavailable'));
            $this->assertStringContainsString(self::BRAND_BACKGROUND, $response->getContent());
        } finally {
            $this->artisan('up')->assertSuccessful();
        }
    }

    #[Test]
    public function maintenance_page_mentions_retry_window_when_retry_after_header_is_present(): void
    {
        $response = $this->renderException(
            new HttpException(503, 'Service Unavailable', null, ['Retry-After' => '120'])
        );

        $response->assertStatus(503);
        $response->assertSee('2 minutes');
    }

    #[Test]
    public function maintenance_page_renders_without_an_exception_instance(): void
    {
        $html = view('errors.503', ['exception' => null])->render();

        $this->assertStringContainsString('503', $html);
        $this->assertStringContainsString(__('Temporarily Unavailable'), $html);
    }

    #[Test]
    public function maintenance_page_renders_when_exception_variable_is_missing(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('503', $html);
        $this->assertStringContainsString(__('Temporarily Unavailable'), $html);
    }

    #[Test]
    public function every_supported_error_status_renders_its_view(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            $html = view('errors.'.$status, ['exception' => null])->render();

            $this->assertStringContainsString((string) $status, $html, "errors/{$status} must display its status code.");
            $this->assertStringContainsString(self::BRAND_BACKGROUND, $html, "errors/{$status} must use the branded stylesheet.");
        }
    }

    private function renderException(\Throwable $exception): TestResponse
    {
        $handler = app(ExceptionHandler::class);
        $rendered = $handler->render(Request::create('/some-page', 'GET'), $exception);

        return TestResponse::fromBaseResponse($rendered);
    }
}

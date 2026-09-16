<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SeoAudit;
use App\Services\OperationalAlertService;
use App\Services\Seo\SeoAuditService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecretExposureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalTrustedProxies = null;

    private ?string $originalAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        // dotenv reads $_SERVER → $_ENV → getenv in that order — capture the
        // boot-time value from the first adapter that has it.
        $this->originalTrustedProxies = $_SERVER['TRUSTED_PROXIES']
            ?? $_ENV['TRUSTED_PROXIES']
            ?? (getenv('TRUSTED_PROXIES') !== false ? getenv('TRUSTED_PROXIES') : null);
        $this->originalAppUrl = config('app.url');
    }

    protected function tearDown(): void
    {
        if ($this->originalTrustedProxies !== null) {
            putenv('TRUSTED_PROXIES='.$this->originalTrustedProxies);
            $_ENV['TRUSTED_PROXIES'] = $this->originalTrustedProxies;
            $_SERVER['TRUSTED_PROXIES'] = $this->originalTrustedProxies;
        } else {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }

        config(['app.url' => $this->originalAppUrl]);

        parent::tearDown();
    }

    /**
     * Transport failures embed the full request URL in the exception message.
     * Slack incoming-webhook URLs carry the secret in the path, so a failed
     * alert POST must never land in the logs unredacted.
     */
    public function test_alert_transport_failure_redacts_webhook_secret_from_logs(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T000FAKE/B111FAKE/SECRETOKENVALUE123';

        config(['services.operational_alerts.webhook_url' => $webhookUrl]);
        config(['services.operational_alerts.critical_webhook_url' => null]);

        Http::fake(function () use ($webhookUrl) {
            throw new ConnectException(
                'cURL error 28: Connection timed out after 10001 milliseconds for '.$webhookUrl,
                new Request('POST', $webhookUrl),
            );
        });
        Log::spy();

        app(OperationalAlertService::class)->alert('Disk almost full', '92% used', 'critical');

        Log::shouldHaveReceived('critical')
            ->withArgs(function (string $message, array $context) use ($webhookUrl) {
                return str_contains($message, 'failed to send webhook alert')
                    && ! str_contains((string) ($context['error'] ?? ''), $webhookUrl)
                    && str_contains((string) ($context['error'] ?? ''), '[REDACTED]');
            })
            ->once();
    }

    public function test_escalation_transport_failure_redacts_webhook_secret_from_logs(): void
    {
        $escalationUrl = 'https://hooks.slack.com/services/T000FAKE/B222FAKE/ESCALATIONTOKEN456';

        config(['services.operational_alerts.webhook_url' => null]);
        config(['services.operational_alerts.escalation_webhook_url' => $escalationUrl]);

        Http::fake(function () use ($escalationUrl) {
            throw new ConnectException(
                'cURL error 6: Could not resolve host: hooks.slack.com for '.$escalationUrl,
                new Request('POST', $escalationUrl),
            );
        });
        Log::spy();

        app(OperationalAlertService::class)->alert('Queue stalled', 'no heartbeat', 'critical', null, true);

        Log::shouldHaveReceived('critical')
            ->withArgs(function (string $message, array $context) use ($escalationUrl) {
                return str_contains($message, 'ESCALATION webhook alert')
                    && ! str_contains((string) ($context['error'] ?? ''), $escalationUrl)
                    && str_contains((string) ($context['error'] ?? ''), '[REDACTED]');
            })
            ->once();
    }

    /**
     * The SEO audit must resolve its Slack webhook through the config layer —
     * a direct env() fallback silently dies under cached configuration.
     */
    public function test_seo_audit_reads_webhook_from_config_layer(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T000FAKE/B333FAKE/SEOAUDITTOKEN789';

        $this->mock(SeoAuditService::class, function ($mock) {
            $mock->shouldReceive('summary')->andReturn($this->fakeSummary());
            $mock->shouldReceive('issues')->andReturn([
                $this->fakeIssue('warning'),
            ]);
        });
        config(['services.operational_alerts.webhook_url' => $webhookUrl]);

        Http::fake();

        $this->assertEquals(0, Artisan::call(SeoAudit::class));

        Http::assertSent(fn ($request) => $request->url() === $webhookUrl);
    }

    public function test_seo_audit_slack_failure_log_is_redacted(): void
    {
        $webhookUrl = 'https://hooks.slack.com/services/T000FAKE/B444FAKE/SEOAUDITFAILTOK012';

        $this->mock(SeoAuditService::class, function ($mock) {
            $mock->shouldReceive('summary')->andReturn($this->fakeSummary());
            $mock->shouldReceive('issues')->andReturn([
                $this->fakeIssue('warning'),
            ]);
        });
        config(['services.operational_alerts.webhook_url' => $webhookUrl]);

        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = ['level' => $event->level, 'message' => $event->message];
        });

        Http::fake(function () use ($webhookUrl) {
            throw new ConnectException(
                'cURL error 28: Operation timed out for '.$webhookUrl,
                new Request('POST', $webhookUrl),
            );
        });

        Artisan::call(SeoAudit::class);

        $warning = collect($records)->first(
            fn (array $record) => str_contains((string) $record['message'], 'SEO audit: Slack notification failed'),
        );

        $this->assertNotNull($warning, 'The SEO audit failure warning was not logged.');
        $this->assertStringNotContainsString($webhookUrl, (string) $warning['message']);
        $this->assertStringContainsString('[REDACTED]', (string) $warning['message']);
    }

    /**
     * An UNSET TRUSTED_PROXIES must hit the production-critical branch of the
     * preflight check (fail-closed), not the overly-permissive advisory.
     */
    public function test_preflight_flags_unset_trusted_proxies_as_production_critical(): void
    {
        $this->simulateProductionEnv();
        $this->setTrustedProxies(null);

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('TRUSTED_PROXIES is empty', $output);
    }

    public function test_preflight_accepts_configured_trusted_proxies(): void
    {
        $this->simulateProductionEnv();
        $this->setTrustedProxies('10.0.1.0/24');

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('TRUSTED_PROXIES is set (10.0.1.0/24)', $output);
    }

    public function test_preflight_flags_wildcard_trusted_proxies_as_overly_permissive(): void
    {
        $this->simulateProductionEnv();
        $this->setTrustedProxies('*');

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('overly permissive', $output);
    }

    /**
     * The browser monitoring bootstrap must stay limited to intentionally
     * public values — the browser DSN, release and environment. No private
     * credential may ride along with the same script block.
     */
    public function test_monitoring_bootstrap_exposes_only_public_configuration(): void
    {
        $publicDsn = 'https://publicingestkey0000000000000000@o000000.ingest.sentry.io/0000000';

        config(['sentry.dsn' => $publicDsn]);
        config(['sentry.release' => 'abc123']);
        config(['sentry.environment' => 'production']);
        config(['services.2checkout.secret_word' => 'fake-private-secret-word']);
        config(['services.2checkout.account_number' => '9999999999']);
        config(['app.key' => 'base64:AAAAfakefakefakefakefakefakefakefakefakefakefakefakefake=']);
        config(['database.connections.mysql.password' => 'fake-db-password']);
        config(['services.resend.key' => 're_fakefakefakefakefakefakefake']);

        $html = view('layouts.partials.monitoring-bootstrap')->render();

        // @js() escapes slashes — assert on the unescaped fragments instead.
        $this->assertStringContainsString('publicingestkey0000000000000000', $html);
        $this->assertStringContainsString('o000000.ingest.sentry.io', $html);
        $this->assertStringContainsString('abc123', $html);
        $this->assertStringNotContainsString('fake-private-secret-word', $html);
        $this->assertStringNotContainsString('fake-db-password', $html);
        $this->assertStringNotContainsString('re_fakefakefakefakefakefakefake', $html);
        $this->assertStringNotContainsString('fakefakefakefakefake', str_replace('AAAAfakefakefakefakefakefakefakefakefakefakefakefakefake=', '', $html));
    }

    private function simulateProductionEnv(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'services.2checkout.account_number' => '255522032322',
            'services.2checkout.secret_word' => 'fake-preflight-secret-word',
            'services.2checkout.product_id_pro' => '53996696',
            'services.2checkout.product_id_studio' => '53996701',
            'services.resend.key' => 're_fakefakefakefakefakefakefake',
            'mail.default' => 'resend',
        ]);
    }

    /**
     * dotenv's read order is $_SERVER → $_ENV → getenv — the value must be
     * set (or cleared) across ALL of them to be deterministic regardless of
     * which .env file was loaded at boot.
     */
    private function setTrustedProxies(?string $value): void
    {
        if ($value === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

            return;
        }

        putenv('TRUSTED_PROXIES='.$value);
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }

    private function fakeSummary(): array
    {
        return [
            'indexable_galleries' => 3,
            'indexable_artists' => 2,
            'indexable_artworks' => 10,
            'published_seo_pages' => 1,
            'active_redirects' => 0,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function fakeIssue(string $severity): array
    {
        return [
            'key' => 'galleries_missing_description',
            'label' => 'Public galleries with no curator description (fallback text used)',
            'count' => 2,
            'severity' => $severity,
        ];
    }
}

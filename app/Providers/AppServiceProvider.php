<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FeatureFlag;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Iter-002 (2CO-1): Register TwoCheckoutApiClient as a singleton.
        // The client is configured from config('services.2checkout') which
        // reads env vars. Singleton so the HTTP client pool is reused.
        $this->app->singleton(TwoCheckoutApiClient::class, function ($app) {
            return new TwoCheckoutApiClient(
                merchantCode: (string) config('services.2checkout.account_number', ''),
                secretWord: (string) config('services.2checkout.secret_word', ''),
                sandbox: (bool) config('services.2checkout.sandbox', false),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This forces the site to use HTTPS when live, fixing the broken styles and login error.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // ── SEO OS (Iteration 4): sitemap cache invalidation ────────────
        // Entities whose changes affect the public URL set bump the sitemap
        // version key, lazily regenerating all versioned sitemap caches.
        // See App\Observers\SitemapCacheObserver for the watched attributes.
        $sitemapObserver = \App\Observers\SitemapCacheObserver::class;
        \App\Models\Gallery::observe($sitemapObserver);
        \App\Models\Artist::observe($sitemapObserver);
        \App\Models\GalleryImage::observe($sitemapObserver);
        // Iteration 5: published/unpublished content pages refresh the
        // content sitemap group too.
        \App\Models\SeoPage::observe($sitemapObserver);
        // ITERATION 5: event writes (announced openings, schedule changes)
        // refresh the sitemap events group.
        \App\Models\GalleryScheduleEvent::observe($sitemapObserver);

        // ── CR-5 FIX (Iter-001): TRUSTED_PROXIES hard-fail in production ──────
        //
        // Previously: only a Log::critical() warning when TRUSTED_PROXIES=*.
        // The warning was advisory — bad deploys still shipped. The .env.example
        // default was TRUSTED_PROXIES=*, so every fresh clone/deploys shipped
        // with the permissive default.
        //
        // Consequences of TRUSTED_PROXIES=* in production:
        //   - Rate-limit bypass: every throttle keys on $request->ip(). With
        //     TRUSTED_PROXIES=*, $request->ip() returns whatever the client
        //     puts in X-Forwarded-For. An attacker rotates that header to
        //     bypass every auth throttle (login, MFA, PIN, forgot-password,
        //     reset-password, webhook, API).
        //   - Host-header spoofing: DetectCustomDomain and ScopeSessionDomain
        //     read $request->getHost(). An attacker sends X-Forwarded-Host:
        //     evil.com and Laravel thinks the request is for evil.com.
        //   - Audit-log IP poisoning: every Log::info(... request->ip()) is
        //     attacker-controlled.
        //
        // FIX:
        //   1. .env.example default changed from '*' to '' (empty = fail-closed).
        //   2. In production, throw a RuntimeException if TRUSTED_PROXIES is
        //      empty or '*'. The exception fires during container boot, which
        //      (combined with the CR-1 preflight fix) prevents the container
        //      from serving traffic with a permissive proxy config.
        //   3. In non-production environments, log a warning but don't throw
        //      (local dev often uses TRUSTED_PROXIES=* for convenience).
        //
        // The correct production value is the Traefik subnet (typically
        // 172.16.0.0/12). Find it via:
        //   docker network inspect coolify-network | grep Subnet
        $trustedProxies = env('TRUSTED_PROXIES');

        // ITERATION-1 FIX (testability): the production guard moved into a
        // static method so it can be unit-tested directly — boot()-time
        // behavior is unchanged. (Fighting phpdotenv's immutable-writer
        // semantics in feature tests to vary TRUSTED_PROXIES across app
        // refreshes proved unreliable: the writer re-writes variables it
        // itself loaded previously, clobbering runtime overrides.)
        if ($this->app->environment('production')) {
            self::assertTrustedProxiesConfigured($trustedProxies);
        } else {
            // Non-production: log a warning but don't throw (local dev convenience).
            if ($trustedProxies === '*') {
                Log::warning('TRUSTED_PROXIES=* is set — acceptable in non-production, but set a specific subnet in production.');
            }
        }

        // M-14: Register Blade directives for feature flags.
        //
        // Usage in Blade:
        //   @featureFlag('subscriptions')
        //       <a href="/billing/upgrade/pro?recurring=1">Subscribe monthly</a>
        //   @endfeatureFlag
        //
        //   @featureFlag('subscriptions', true)
        //       {{-- Show this block when subscriptions are DISABLED --}}
        //       <p>Subscriptions coming soon!</p>
        //   @endfeatureFlag
        //
        // The second argument (optional) inverts the check — when true,
        // the block renders when the flag is DISABLED (useful for "coming
        // soon" fallbacks).
        Blade::if('featureFlag', function (string $flag, bool $whenDisabled = false) {
            return $whenDisabled
                ? ! FeatureFlag::isEnabled($flag)
                : FeatureFlag::isEnabled($flag);
        });

        // M-15: Register Blade directive for A/B testing.
        // Usage: @abVariant('pricing_cta', 'B') ... @endabVariant
        // Renders the block only if the current user is assigned to variant B
        // of the 'pricing_cta' experiment.
        Blade::if('abVariant', function (string $experiment, string $variant) {
            return \App\Services\ABTest::isVariant($experiment, $variant);
        });

        // D-8 FIX (Iter-004): Register @nonce Blade directive for CSP nonces.
        //
        // Usage in Blade:
        //   <script nonce="@nonce">
        //     // inline JS here
        //   </script>
        //
        // The nonce is generated per-request by SecurityHeaders middleware
        // and stored in the request attributes. The directive reads it and
        // outputs the nonce value. If the middleware didn't run (e.g. in
        // tests), the directive outputs an empty string (no nonce — the
        // script won't execute under strict CSP, but that's correct for
        // tests which don't enforce CSP).
        Blade::directive('nonce', function () {
            return '<?php echo csp_nonce(); ?>';
        });

        // D-8: csp_nonce() now lives in app/helpers.php (Composer
        // "autoload.files"), NOT here. route:cache/event:cache boot the
        // application a second time in-process, which was redeclaring this
        // function when it lived inside boot(). See app/helpers.php for
        // the full explanation.
    }

    /**
     * CR-5: fail the boot when TRUSTED_PROXIES is empty or '*' in production.
     *
     * Static + public so the guard itself is unit-testable without
     * booting a full application with a mutated environment (phpdotenv's
     * immutable writer re-writes variables it loaded earlier, so runtime
     * env overrides cannot reliably simulate fresh production boots).
     *
     * @throws \RuntimeException when the proxy configuration is unsafe.
     */
    public static function assertTrustedProxiesConfigured(?string $trustedProxies): void
    {
        if (empty($trustedProxies) || $trustedProxies === '*') {
            $message = sprintf(
                "FATAL: TRUSTED_PROXIES is set to '%s' in production. " .
                "This enables host-header spoofing and rate-limit bypass attacks. " .
                "Set TRUSTED_PROXIES to your Coolify Traefik subnet " .
                "(find via: docker network inspect coolify-network | grep Subnet). " .
                "Typical value: 172.16.0.0/12",
                $trustedProxies ?: '(empty)',
            );

            Log::critical($message);

            // Throw to prevent the container from serving traffic.
            // The CR-1 preflight fix in docker-start.sh will catch this
            // and exit 1, marking the deploy as failed.
            throw new \RuntimeException($message);
        }
    }
}

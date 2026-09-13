<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FeatureFlag;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TwoCheckoutApiClient::class, function ($app) {
            return new TwoCheckoutApiClient(
                merchantCode: (string) config('services.2checkout.account_number', ''),
                secretWord: (string) config('services.2checkout.secret_word', ''),
                sandbox: (bool) config('services.2checkout.sandbox', false),
            );
        });
    }

    public function boot(): void
    {
        PasswordRule::defaults(fn () => PasswordRule::min(8));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('verification-link', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('verification-resend', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        $sitemapObserver = \App\Observers\SitemapCacheObserver::class;
        \App\Models\Gallery::observe($sitemapObserver);
        \App\Models\Artist::observe($sitemapObserver);
        \App\Models\GalleryImage::observe($sitemapObserver);
        \App\Models\SeoPage::observe($sitemapObserver);
        \App\Models\GalleryScheduleEvent::observe($sitemapObserver);
        \App\Models\VenueTemplate::observe($sitemapObserver);

        $trustedProxies = env('TRUSTED_PROXIES');

        if ($this->app->environment('production')) {
            self::assertTrustedProxiesConfigured($trustedProxies);
        } else {
            // Non-production: log a warning but don't throw (local dev convenience).
            if ($trustedProxies === '*') {
                Log::warning('TRUSTED_PROXIES=* is set — acceptable in non-production, but set a specific subnet in production.');
            }
        }

        Blade::if('featureFlag', function (string $flag, bool $whenDisabled = false) {
            return $whenDisabled
                ? ! FeatureFlag::isEnabled($flag)
                : FeatureFlag::isEnabled($flag);
        });

        Blade::if('abVariant', function (string $experiment, string $variant) {
            return \App\Services\ABTest::isVariant($experiment, $variant);
        });

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

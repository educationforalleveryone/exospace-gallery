<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\EncryptBackupArchive;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\GalleryScheduleEvent;
use App\Models\SeoPage;
use App\Models\VenueTemplate;
use App\Observers\SitemapCacheObserver;
use App\Services\ABTest;
use App\Services\FeatureFlag;
use App\Services\QueueWorkerHeartbeat;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Backup\Events\BackupZipWasCreated;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // spatie calls its own EncryptBackupArchive directly when notifications
        // are disabled; route that call to our libsodium implementation too.
        $this->app->bind(\Spatie\Backup\Listeners\EncryptBackupArchive::class, EncryptBackupArchive::class);

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

        // Encrypt each finished backup archive before it is copied to the
        // destination disks (spatie's zip-level AES is unavailable in prod).
        Event::listen(BackupZipWasCreated::class, EncryptBackupArchive::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('verification-link', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // An account can be registered with someone else's address, so cap
        // resends per hour as well to stop it being used to mail-bomb them.
        RateLimiter::for('verification-resend', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(6)->by($key),
                Limit::perHour(10)->by($key),
            ];
        });

        // Queue worker liveness — OperationalAlertService alerts when this
        // heartbeat goes stale. Only the worker daemon fires Looping events;
        // web/console processes never do.
        Queue::looping(fn () => QueueWorkerHeartbeat::stamp());

        $sitemapObserver = SitemapCacheObserver::class;
        Gallery::observe($sitemapObserver);
        Artist::observe($sitemapObserver);
        GalleryImage::observe($sitemapObserver);
        SeoPage::observe($sitemapObserver);
        GalleryScheduleEvent::observe($sitemapObserver);
        VenueTemplate::observe($sitemapObserver);

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
            return ABTest::isVariant($experiment, $variant);
        });

        Blade::directive('nonce', function () {
            return '<?php echo csp_nonce(); ?>';
        });

        // csp_nonce() lives in app/helpers.php (Composer
        // "autoload.files"), NOT here. route:cache/event:cache boot the
        // application a second time in-process, which was redeclaring this
        // function when it lived inside boot(). See app/helpers.php for
        // the full explanation.
    }

    /**
     * Fail the boot when TRUSTED_PROXIES is empty or '*' in production.
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
                "FATAL: TRUSTED_PROXIES is set to '%s' in production. ".
                'This enables host-header spoofing and rate-limit bypass attacks. '.
                'Set TRUSTED_PROXIES to your Coolify Traefik subnet '.
                '(find via: docker network inspect coolify-network | grep Subnet). '.
                'Typical value: 172.16.0.0/12',
                $trustedProxies ?: '(empty)',
            );

            Log::critical($message);

            // Throw to prevent the container from serving traffic.
            // The preflight check in docker-start.sh will catch this
            // and exit 1, marking the deploy as failed.
            throw new \RuntimeException($message);
        }
    }
}

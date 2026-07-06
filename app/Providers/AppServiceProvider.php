<?php

namespace App\Providers;

use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}

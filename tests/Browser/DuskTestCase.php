<?php

namespace Tests\Browser;

use Laravel\Dusk\TestCase as BaseTestCase;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;

/**
 * TD-31 FIX: Base Dusk test case.
 *
 * Browser tests run via ChromeDriver (headless Chrome). The CI workflow
 * (added in this iteration) installs ChromeDriver + runs `php artisan dusk`.
 *
 * Local dev: install ChromeDriver via `php artisan dusk:chrome-driver`,
 * then run `php artisan dusk`.
 *
 * Dusk uses its own .env file (.env.dusk.local / .env.dusk.testing) so
 * browser tests don't clobber your dev database. The CI workflow creates
 * .env.dusk.testing from .env.example with sqlite in-memory.
 */
abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            '--disable-gpu',
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1920,1080',
            // Disable features that slow down headless Chrome or cause flaky tests
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-sync',
        ])->unless($this->hasHeadlessDisabled(), function ($items) {
            return $items->merge(['--disable-gpu']);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY,
                $options
            )
        );
    }
}

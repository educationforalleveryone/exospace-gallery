<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ops\Console\CorrelateIncidentsCommand;
use App\Ops\Console\PruneOpsEventsCommand;
use App\Ops\Console\SweepCredentialsCommand;
use App\Ops\Console\SweepDiagnosticsCommand;
use App\Ops\Console\SyncPlatformCommand;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Support\ServiceProvider;

/**
 * OpsCenter — OpsServiceProvider.
 *
 * Registers the Ops module's console commands (they live outside
 * app/Console/Commands so Laravel's auto-discovery doesn't pick them up —
 * registration is explicit, per ADR-1's module isolation) and ensures the
 * "self" application row exists after boot.
 *
 * Everything else about the module wires itself through standard Laravel
 * conventions: config/ops.php, migrations, routes mounted in
 * routes/web.php + routes/api.php, views in resources/views/ops.
 */
class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Config merge not needed: config/ops.php is a root config file
        // (config:cache-safe, env reads inside it only).
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPlatformCommand::class,
                PruneOpsEventsCommand::class,
                CorrelateIncidentsCommand::class,
                SweepDiagnosticsCommand::class,
                SweepCredentialsCommand::class,
            ]);
        }

        // Ensure the self row exists so local errors are attributed from
        // the very first request (deferred + failure-tolerant: migrations
        // may not have run yet on a fresh install).
        $this->callAfterResolving(OpsEventIngestor::class, function (OpsEventIngestor $ingestor): void {
            try {
                OpsEventIngestor::selfApplication();
            } catch (\Throwable) {
                // Pre-migration window — the ingestor handles a missing
                // self row gracefully on every call.
            }
        });
    }
}

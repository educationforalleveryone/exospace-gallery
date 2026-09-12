<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ops\Console\CheckDigestDeliveryCommand;
use App\Ops\Console\CorrelateIncidentsCommand;
use App\Ops\Console\PruneOpsEventsCommand;
use App\Ops\Console\SendMorningDigestCommand;
use App\Ops\Console\SendWeeklyReviewCommand;
use App\Ops\Console\SweepCredentialsCommand;
use App\Ops\Console\SweepDiagnosticsCommand;
use App\Ops\Console\SyncPlatformCommand;
use App\Ops\Services\OpsEventIngestor;
use Illuminate\Support\ServiceProvider;

class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
                SendMorningDigestCommand::class,
                SendWeeklyReviewCommand::class,
                CheckDigestDeliveryCommand::class,
            ]);
        }

        $this->callAfterResolving(OpsEventIngestor::class, function (OpsEventIngestor $ingestor): void {
            try {
                OpsEventIngestor::selfApplication();
            } catch (\Throwable) {
            }
        });
    }
}

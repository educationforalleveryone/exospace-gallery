<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\PlatformSyncService;
use Illuminate\Console\Command;

class SyncPlatformCommand extends Command
{
    protected $signature = 'ops:sync-platform';

    protected $description = 'Sync Coolify platform state (apps, databases, services, deployments) into the OpsCenter control plane.';

    public function handle(PlatformSyncService $sync): int
    {
        if (! config('ops.platform_sync.enabled')) {
            $this->line('Platform sync disabled (OPS_PLATFORM_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! app(\App\Ops\Services\CoolifyApiClient::class)->isConfigured()) {
            $this->warn('Coolify API not configured (COOLIFY_API_TOKEN / COOLIFY_API_BASE_URL missing).');
            $this->line('The control plane will still ingest local errors and ingest-API events.');

            return self::SUCCESS;
        }

        $result = $sync->sync();

        if ($result['api_ok']) {
            $this->info(sprintf(
                'Platform sync OK — %d resources, %d new event(s).',
                $result['applications'],
                $result['events_created'],
            ));

            return self::SUCCESS;
        }

        // API configured but unreachable → observable, non-fatal.
        $sync->recordApiUnreachable();
        $this->warn('Coolify API unreachable — recorded an infrastructure event (rate-limited).');

        return self::SUCCESS;
    }
}

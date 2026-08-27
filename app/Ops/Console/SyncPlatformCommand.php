<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\PlatformSyncService;
use Illuminate\Console\Command;

/**
 * OpsCenter — ops:sync-platform.
 *
 * Pulls the whole Coolify platform (servers, applications, databases,
 * services, recent deployments) into the ops tables. Scheduled every 5
 * minutes from routes/console.php; safe to run manually at any time
 * (idempotent, overlap-protected on the schedule).
 *
 * When the Coolify API is unreachable the command records a rate-limited
 * INFRASTRUCTURE event (via PlatformSyncService::recordApiUnreachable)
 * and exits 0 — a monitoring feed being down must never fail the
 * scheduler chain that hosts the OTHER alerts.
 */
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

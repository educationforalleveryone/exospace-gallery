<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\IncidentCorrelationService;
use Illuminate\Console\Command;

/**
 * OpsCenter — ops:correlate-incidents (Iteration 2).
 *
 * The correlation sweep: groups unlinked error/critical events into
 * incidents (adopting into open incidents, detecting causal chains,
 * clustering, or creating solo incidents). Scheduled every 5 minutes —
 * immediately after ops:sync-platform so freshly synced deployment
 * failures correlate with the errors that followed them.
 *
 * Idempotent (correlation_key is unique; already-linked events are
 * skipped) and non-fatal on failure (retried next run).
 */
class CorrelateIncidentsCommand extends Command
{
    protected $signature = 'ops:correlate-incidents';

    protected $description = 'Correlate unlinked ops events into incidents (adopt, chain-detect, cluster, solo).';

    public function handle(IncidentCorrelationService $service): int
    {
        $result = $service->correlateAll();

        $this->info(sprintf(
            'Incident correlation done — %d incident(s) created, %d event(s) adopted.',
            $result['incidents_created'],
            $result['events_adopted'],
        ));

        return self::SUCCESS;
    }
}

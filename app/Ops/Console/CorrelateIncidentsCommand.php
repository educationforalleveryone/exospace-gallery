<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\IncidentCorrelationService;
use Illuminate\Console\Command;

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

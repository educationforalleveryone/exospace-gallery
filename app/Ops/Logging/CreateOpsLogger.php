<?php

declare(strict_types=1);

namespace App\Ops\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * OpsCenter — CreateOpsLogger.
 *
 * Factory for the custom 'ops' logging channel (wired in
 * config/logging.php via 'driver' => 'custom', 'via' => this class).
 *
 * Returns a Monolog logger whose ONLY handler is the OpsDatabaseHandler
 * (the tap). Because this channel is separate from 'daily'/'json', adding
 * it to LOG_STACK is purely additive — existing channels keep every record
 * exactly as today.
 */
class CreateOpsLogger
{
    /**
     * @param  array{level?: string|int}  $config
     */
    public function __invoke(array $config): Logger
    {
        $level = Level::fromName(ucfirst(strtolower((string) ($config['level'] ?? 'warning'))));

        return new Logger('ops', [
            new OpsDatabaseHandler($level),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Logging;

use Monolog\Level;
use Monolog\Logger;

class CreateOpsLogger
{
    public function __invoke(array $config): Logger
    {
        $level = Level::fromName(ucfirst(strtolower((string) ($config['level'] ?? 'warning'))));

        return new Logger('ops', [
            new OpsDatabaseHandler($level),
        ]);
    }
}

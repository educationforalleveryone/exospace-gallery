<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Services\ArtisanCommandRunner;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunMonitoredBackup extends Command
{
    protected $signature = 'exospace:backup
                            {type : db | files | clean}';

    protected $description = 'Run a monitored spatie backup job (db | files | clean) with heartbeat stamping + Slack alerting.';

    private const TYPES = [
        'db' => [
            'command'  => 'backup:run',
            'params'   => ['--only-db' => true],
            'heartbeat'=> 'exospace:backup:db',
            'severity' => 'critical',
            'label'    => 'Daily database backup',
        ],
        'files' => [
            'command'  => 'backup:run',
            'params'   => ['--only-files' => true],
            'heartbeat'=> 'exospace:backup:files',
            'severity' => 'critical',
            'label'    => 'Weekly file backup',
        ],
        'clean' => [
            'command'  => 'backup:clean',
            'params'   => [],
            'heartbeat'=> 'exospace:backup:clean',
            'severity' => 'warning',
            'label'    => 'Backup cleanup',
        ],
    ];

    public function handle(ArtisanCommandRunner $runner, JobHeartbeatService $heartbeats, OperationalAlertService $alerts): int
    {
        $type = (string) $this->argument('type');

        if (! array_key_exists($type, self::TYPES)) {
            $this->error("Unknown backup type '{$type}'. Valid: db, files, clean.");
            return self::FAILURE;
        }

        $config = self::TYPES[$type];
        $this->info("Running {$config['label']} ({$config['command']})...");

        $exit = $runner($config['command'], $config['params']);

        if ($exit === 0) {
            $heartbeats->stamp($config['heartbeat']);
            $this->info("{$config['label']} completed — heartbeat stamped.");
            return self::SUCCESS;
        }

        $this->error("{$config['label']} failed (exit {$exit}).");

        $diagnostic = $runner->lastOutput();
        $tail = strlen($diagnostic) > 300
            ? substr($diagnostic, -300)
            : $diagnostic;
        $tailBlock = $tail !== ''
            ? "\n\nUnderlying spatie output (last 300 chars):\n```\n" . trim($tail) . "\n```"
            : '';

        $alerts->alert(
            "{$config['label']} failed",
            "The monitored spatie `{$config['command']}` invocation exited with code {$exit}. "
            . "The heartbeat is left unstamped — the JobHeartbeat monitor will also surface this as a "
            . "stale job within its maxAge window. Check storage/logs/laravel.log + the spatie "
            . "backup:list output; the underlying failure (no mysqldump, full disk, broken disk "
            . "credential, etc.) is logged there." . $tailBlock,
            $config['severity'],
            'backup_failed:' . $type,
        );

        Log::error('RunMonitoredBackup: spatie command failed', [
            'type'         => $type,
            'command'      => $config['command'],
            'exit_code'    => $exit,
            'heartbeat'    => $config['heartbeat'],
        ]);

        try {
            $target = \App\Models\Transaction::orderByDesc('id')->first();

            if ($target !== null) {
                AdminAuditLog::record('backup.failed', $target, [
                    'type'       => $type,
                    'command'    => $config['command'],
                    'exit_code'  => $exit,
                    'heartbeat'  => $config['heartbeat'],
                ]);
            } else {
                Log::info('RunMonitoredBackup: audit row skipped — no transactions exist to target.');
            }
        } catch (\Throwable $e) {
            Log::warning('RunMonitoredBackup: audit row skipped', ['error' => $e->getMessage()]);
        }

        return self::FAILURE;
    }
}

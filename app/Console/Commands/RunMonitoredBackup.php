<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Services\ArtisanCommandRunner;
use App\Services\BackupArtifactVerifier;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunMonitoredBackup extends Command
{
    protected $signature = 'exospace:backup
                            {type : db | files | clean}';

    protected $description = 'Run a monitored spatie backup job (db | files | clean) with artifact verification, heartbeat stamping + Slack alerting.';

    private const TYPES = [
        'db' => [
            'command' => 'backup:run',
            'params' => ['--only-db' => true],
            'heartbeat' => 'exospace:backup:db',
            'severity' => 'critical',
            'label' => 'Daily database backup',
        ],
        'files' => [
            'command' => 'backup:run',
            'params' => ['--only-files' => true],
            'heartbeat' => 'exospace:backup:files',
            'severity' => 'critical',
            'label' => 'Weekly file backup',
        ],
        'clean' => [
            'command' => 'backup:clean',
            'params' => [],
            'heartbeat' => 'exospace:backup:clean',
            'severity' => 'warning',
            'label' => 'Backup cleanup',
        ],
    ];

    public function handle(
        ArtisanCommandRunner $runner,
        JobHeartbeatService $heartbeats,
        OperationalAlertService $alerts,
        BackupArtifactVerifier $verifier,
    ): int {
        $type = (string) $this->argument('type');

        if (! array_key_exists($type, self::TYPES)) {
            $this->error("Unknown backup type '{$type}'. Valid: db, files, clean.");

            return self::FAILURE;
        }

        $config = self::TYPES[$type];
        $this->info("Running {$config['label']} ({$config['command']})...");

        $exit = $runner($config['command'], $config['params']);

        if ($exit !== 0) {
            $tail = $this->spatieTail($runner);

            $this->reportFailure($config, $type, $alerts, $exit, $tail, null);

            return self::FAILURE;
        }

        // spatie exited 0 — that only proves the process ran. Verify the
        // stored artifacts before declaring a usable recovery point; a
        // truncated upload or corrupt zip must not look like success.
        if ($type !== 'clean') {
            $verificationErrors = $this->verifyDestinationDisks($verifier);

            if ($verificationErrors !== []) {
                $this->reportFailure($config, $type, $alerts, 0, '', $verificationErrors);

                return self::FAILURE;
            }

            $this->info('Artifact verification passed on all destination disks.');
        }

        $heartbeats->stamp($config['heartbeat']);
        $this->info("{$config['label']} completed — heartbeat stamped.");

        return self::SUCCESS;
    }

    /**
     * Verify the newest artifact on every configured destination disk.
     *
     * @return list<string> per-disk error descriptions (empty = all usable)
     */
    private function verifyDestinationDisks(BackupArtifactVerifier $verifier): array
    {
        $errors = [];

        foreach (BackupArtifactVerifier::destinationDiskNames() as $diskName) {
            $report = $verifier->verifyNewestOnDisk($diskName);

            foreach ($report->errors as $error) {
                $errors[] = "disk '{$diskName}': {$error}";
            }
        }

        return $errors;
    }

    private function spatieTail(ArtisanCommandRunner $runner): string
    {
        $diagnostic = $runner->lastOutput();

        if (strlen($diagnostic) > 300) {
            $diagnostic = substr($diagnostic, -300);
        }

        return $diagnostic !== ''
            ? "\n\nUnderlying spatie output (last 300 chars):\n```\n".trim($diagnostic)."\n```"
            : '';
    }

    /**
     * @param  array{command: string, heartbeat: string, severity: string, label: string}  $config
     * @param  list<string>|null  $verificationErrors
     */
    private function reportFailure(array $config, string $type, OperationalAlertService $alerts, int $exit, string $tail, ?array $verificationErrors): void
    {
        $isVerificationFailure = $verificationErrors !== null;

        $title = $isVerificationFailure
            ? "{$config['label']} produced an unusable artifact"
            : "{$config['label']} failed";

        if ($isVerificationFailure) {
            $message = "spatie `{$config['command']}` exited 0 but verification of the stored artifact failed:\n- "
                .implode("\n- ", $verificationErrors)
                ."\n\nThe heartbeat is left unstamped — the JobHeartbeat monitor will also surface this as a stale job. "
                .'Run `php artisan exospace:backup:verify` to inspect what is stored on each disk.';
            $this->error("{$config['label']} verification failed:\n- ".implode("\n- ", $verificationErrors));
        } else {
            $message = "The monitored spatie `{$config['command']}` invocation exited with code {$exit}. "
                .'The heartbeat is left unstamped — the JobHeartbeat monitor will also surface this as a '
                .'stale job within its maxAge window. Check storage/logs/laravel.log + the spatie '
                .'backup:list output; the underlying failure (no mysqldump, full disk, broken disk '
                .'credential, etc.) is logged there.'.$tail;
            $this->error("{$config['label']} failed (exit {$exit}).");
        }

        $alerts->alert(
            $title,
            $message,
            $config['severity'],
            ($isVerificationFailure ? 'backup_verify_failed:' : 'backup_failed:').$type,
        );

        Log::error('RunMonitoredBackup: spatie command failed', [
            'type' => $type,
            'command' => $config['command'],
            'exit_code' => $exit,
            'verification' => $verificationErrors,
            'heartbeat' => $config['heartbeat'],
        ]);

        try {
            $target = \App\Models\Transaction::orderByDesc('id')->first();

            if ($target !== null) {
                AdminAuditLog::record('backup.failed', $target, [
                    'type' => $type,
                    'command' => $config['command'],
                    'exit_code' => $exit,
                    'verification' => $verificationErrors,
                    'heartbeat' => $config['heartbeat'],
                ]);
            } else {
                Log::info('RunMonitoredBackup: audit row skipped — no transactions exist to target.');
            }
        } catch (\Throwable $e) {
            Log::warning('RunMonitoredBackup: audit row skipped', ['error' => $e->getMessage()]);
        }
    }
}

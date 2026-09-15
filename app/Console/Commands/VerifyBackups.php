<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArtifactReport;
use App\Services\BackupArtifactVerifier;
use Illuminate\Console\Command;

class VerifyBackups extends Command
{
    protected $signature = 'exospace:backup:verify
                            {--disk= : Verify only this configured backup disk (default: every disk in BACKUP_DISKS)}
                            {--file= : Verify this specific backup file (relative to the backup directory) instead of the newest}';

    protected $description = 'Verify that stored backup archives are readable, decryptable, and contain recoverable data. Read-only.';

    public function handle(BackupArtifactVerifier $verifier): int
    {
        $disks = $this->resolveDisks();

        if ($disks === []) {
            $this->error('No backup disks configured — set BACKUP_DISKS (e.g. "local,r2").');

            return self::FAILURE;
        }

        $specificFile = $this->option('file');
        $failed = false;
        $rows = [];

        foreach ($disks as $diskName) {
            $report = $specificFile !== null
                ? $verifier->verify($diskName, (string) $specificFile)
                : $verifier->verifyNewestOnDisk($diskName);

            $rows[] = $this->describe($report);

            if (! $report->passes()) {
                $failed = true;
            }
        }

        $this->table(
            ['Disk', 'Artifact', 'Size', 'Age', 'DB dumps', 'Media files', 'Encrypted', 'Result'],
            $rows,
        );

        if ($failed) {
            $this->error('❌  Backup verification FAILED — at least one disk has no usable recovery point.');

            return self::FAILURE;
        }

        $this->info('✅  All verified backup artifacts are readable and contain recoverable data.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveDisks(): array
    {
        $configured = BackupArtifactVerifier::destinationDiskNames();

        $only = $this->option('disk');

        if (is_string($only) && $only !== '') {
            return [$only];
        }

        return $configured;
    }

    /**
     * @return list<string>
     */
    private function describe(BackupArtifactReport $report): array
    {
        return [
            $report->disk,
            $report->file !== null ? basename($report->file) : '(none found)',
            $report->sizeBytes !== null ? \Spatie\Backup\Helpers\Format::humanReadableSize($report->sizeBytes) : '-',
            $report->lastModified !== null ? \Illuminate\Support\Carbon::createFromTimestamp($report->lastModified)->diffForHumans(short: true) : '-',
            (string) $report->dbDumpEntries,
            (string) $report->mediaEntries,
            $report->encrypted ? 'yes' : 'no',
            $report->passes() ? '✅ OK' : '❌ '.$report->problemSummary(),
        ];
    }
}

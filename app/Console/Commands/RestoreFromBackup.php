<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Services\BackupArtifactVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class RestoreFromBackup extends Command
{
    protected $signature = 'exospace:backup:restore
                            {--disk= : Backup disk to restore from (default: first entry of BACKUP_DISKS)}
                            {--file= : Specific backup file (relative to the backup directory); default: newest on the disk}
                            {--list : List available backups on the disk and exit}
                            {--only-db : Restore only the database dump from the archive}
                            {--only-files : Restore only the media/files from the archive}
                            {--execute : Perform the restore (default is plan-only — nothing is modified)}
                            {--confirm= : Typed confirmation token; must be RESTORE when executing non-interactively}';

    protected $description = 'Restore Exospace database/media from a backup archive. Plan-only unless --execute with typed confirmation is given.';

    private const PUBLIC_PREFIX = 'storage/app/public/';

    private const PRIVATE_PREFIX = 'storage/app/private/';

    public function handle(BackupArtifactVerifier $verifier): int
    {
        $diskName = $this->resolveDisk();

        if ($this->option('list')) {
            return $this->listBackups($verifier, $diskName);
        }

        $fileName = $this->option('file') ?? $verifier->newestBackupFile($diskName);

        if (! is_string($fileName) || $fileName === '') {
            $this->error("No backup files found on disk '{$diskName}'.");

            return self::FAILURE;
        }

        $report = $verifier->verify($diskName, $fileName);

        if (! $report->passes()) {
            $this->error("Backup artifact '{$fileName}' failed verification on disk '{$diskName}':");
            $this->error('  '.$report->problemSummary());
            $this->line('Refusing to restore from an unusable artifact. Run exospace:backup:verify to inspect candidates.');

            return self::FAILURE;
        }

        $zipPath = $this->openArchiveLocally($diskName, $fileName);

        if ($zipPath === null) {
            $this->error("Could not obtain a local copy of '{$fileName}' from disk '{$diskName}'.");

            return self::FAILURE;
        }

        try {
            $plan = $this->buildPlan($zipPath);

            $this->printPlan($diskName, $fileName, $plan);

            if (! $this->option('execute')) {
                $this->line('');
                $this->info('PLAN ONLY — nothing was modified. Re-run with --execute --confirm=RESTORE to apply.');

                return self::SUCCESS;
            }

            if (! $this->confirmExecution($diskName, $fileName, $plan)) {
                $this->warn('Restore aborted — confirmation did not match.');

                return self::FAILURE;
            }

            return $this->runRestore($zipPath, $plan);
        } finally {
            if (! $this->isLocalArtifact($diskName, $fileName)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * @return list<list<string>>
     */
    private function listBackups(BackupArtifactVerifier $verifier, string $diskName): int
    {
        $backups = $verifier->availableBackups($diskName);

        if ($backups === []) {
            $this->warn("No backup zip files found on disk '{$diskName}' under '".config('backup.backup.name')."'.");

            return self::FAILURE;
        }

        $disk = Storage::disk($diskName);
        $rows = [];

        foreach ($backups as $file) {
            $rows[] = [
                $file === $backups[0] ? 'newest' : '',
                basename($file),
                \Spatie\Backup\Helpers\Format::humanReadableSize((int) $disk->size($file)),
                \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($file))->format('Y-m-d H:i:s T'),
            ];
        }

        $this->table(['', 'File', 'Size', 'Modified'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array{db: bool, files: bool, dumpEntry: ?string, fileEntries: int}
     */
    private function buildPlan(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return ['db' => false, 'files' => false, 'dumpEntry' => null, 'fileEntries' => 0];
        }

        $password = config('backup.backup.password');

        if (is_string($password) && $password !== '') {
            $zip->setPassword($password);
        }

        $dumpEntry = null;
        $fileEntries = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_ends_with($name, '/') || $name === '') {
                continue;
            }

            if (str_contains($name, 'db-dumps/')
                && (str_ends_with($name, '.sql') || str_ends_with($name, '.sql.gz'))) {
                $dumpEntry = $name;

                continue;
            }

            if ($this->mapToLocalPath($name) !== null) {
                $fileEntries++;
            }
        }

        $zip->close();

        $wantDb = ! (bool) $this->option('only-files');
        $wantFiles = ! (bool) $this->option('only-db');

        return [
            'db' => $wantDb && $dumpEntry !== null,
            'files' => $wantFiles && $fileEntries > 0,
            'dumpEntry' => $dumpEntry,
            'fileEntries' => $fileEntries,
        ];
    }

    /**
     * @param  array{db: bool, files: bool, dumpEntry: ?string, fileEntries: int}  $plan
     */
    private function printPlan(string $diskName, string $fileName, array $plan): void
    {
        $connection = config('database.default');
        $targetDb = config("database.connections.{$connection}.database");

        $this->info('RESTORE PLAN');
        $this->line("  Source          : disk '{$diskName}', file '{$fileName}'");
        $this->line("  Target database : connection '{$connection}'".($targetDb !== null ? " ({$targetDb})" : ''));
        $this->line('  Target storage  : storage/app/public (public disk), storage/app/private (private disk)');

        if ($plan['db']) {
            $this->line("  Database        : WILL import dump entry '{$plan['dumpEntry']}' — replaces current contents of the target database");
        } elseif (! $this->option('only-files') && $plan['dumpEntry'] === null) {
            $this->line('  Database        : archive contains no database dump — skipped');
        }

        if ($plan['files']) {
            $this->line("  Media           : WILL extract {$plan['fileEntries']} file(s) — overwrites existing paths, never deletes");
        } elseif (! $this->option('only-db') && $plan['fileEntries'] === 0) {
            $this->line('  Media           : archive contains no storage/app media entries — skipped');
        }
    }

    /**
     * @param  array{db: bool, files: bool, dumpEntry: ?string, fileEntries: int}  $plan
     */
    private function confirmExecution(string $diskName, string $fileName, array $plan): bool
    {
        $token = $this->option('confirm');

        if (is_string($token) && $token !== '') {
            return $token === 'RESTORE';
        }

        if (! $this->input->isInteractive()) {
            $this->error('Non-interactive execution requires --confirm=RESTORE.');

            return false;
        }

        $this->line("About to restore from disk '{$diskName}', file '{$fileName}'.");
        $answer = $this->ask('Type RESTORE to continue');

        return $answer === 'RESTORE';
    }

    /**
     * @param  array{db: bool, files: bool, dumpEntry: ?string, fileEntries: int}  $plan
     */
    private function runRestore(string $zipPath, array $plan): int
    {
        $exit = self::SUCCESS;

        if ($plan['db']) {
            $exit = $this->restoreDatabase($zipPath, (string) $plan['dumpEntry']) === true ? self::SUCCESS : self::FAILURE;
        }

        if ($exit === self::SUCCESS && $plan['files']) {
            $exit = $this->restoreFiles($zipPath);
        }

        if ($exit === self::SUCCESS) {
            Log::warning('exospace:backup:restore completed', [
                'database' => $plan['db'],
                'files' => $plan['files'],
                'dump_entry' => $plan['dumpEntry'],
                'actor_id' => auth()->id(),
            ]);

            $this->line('');
            $this->info('Restore finished. Next steps:');
            $this->line('  1. php artisan config:clear && php artisan cache:clear');
            $this->line('  2. php artisan migrate:status — confirm schema matches expectations');
            $this->line('  3. php artisan storage:link — re-create the public storage symlink');
            $this->line('  4. Run the post-recovery verification checklist (docs/DISASTER-RECOVERY.md §5).');
        }

        return $exit;
    }

    private function restoreDatabase(string $zipPath, string $dumpEntry): bool
    {
        $connection = (string) config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            $this->error("Database restore requires a mysql connection — '{$connection}' is not. Import the dump manually (see docs/DISASTER-RECOVERY.md §3.2).");

            return false;
        }

        if ($this->resolveBinary('mysql') === null) {
            $this->error('The mysql client binary is not available in this environment. Install it or import the dump manually (see docs/DISASTER-RECOVERY.md §3.2).');

            return false;
        }

        $tempDump = tempnam(sys_get_temp_dir(), 'exospace-restore-');

        try {
            if (! $this->extractEntry($zipPath, $dumpEntry, $tempDump)) {
                $this->error("Failed to extract dump entry '{$dumpEntry}' from the archive.");

                return false;
            }

            if (str_ends_with($dumpEntry, '.gz')) {
                $decompressed = $tempDump.'.sql';

                if (! $this->gunzipFile($tempDump, $decompressed)) {
                    $this->error('Failed to decompress the gzipped database dump.');

                    return false;
                }

                @unlink($tempDump);
                $tempDump = $decompressed;
            }

            return $this->importDump($connection, $tempDump);
        } finally {
            @unlink($tempDump);
        }
    }

    private function importDump(string $connection, string $dumpPath): bool
    {
        $host = (string) config("database.connections.{$connection}.host");
        $port = (string) config("database.connections.{$connection}.port", '3306');
        $user = (string) config("database.connections.{$connection}.username");
        $database = (string) config("database.connections.{$connection}.database");
        $password = (string) config("database.connections.{$connection}.password");

        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($database),
        );

        $descriptors = [
            0 => ['file', $dumpPath, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // The password is passed through the process environment so it never
        // appears in the process list or in any captured output.
        $process = proc_open($command, $descriptors, $pipes, null, ['MYSQL_PWD' => $password]);

        if (! is_resource($process)) {
            $this->error('Failed to start the mysql client.');

            return false;
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->error("mysql import exited with code {$exitCode}:");
            $this->error(trim((string) $stderr));

            return false;
        }

        $this->info("Database dump imported into '{$database}'.");

        $this->recordRestoreAudit($connection, $database);

        return true;
    }

    private function recordRestoreAudit(string $connection, string $database): void
    {
        try {
            $target = \App\Models\Transaction::orderByDesc('id')->first();

            if ($target !== null) {
                AdminAuditLog::record('backup.restored', $target, [
                    'connection' => $connection,
                    'database' => $database,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('exospace:backup:restore: audit row skipped', ['error' => $e->getMessage()]);
        }
    }

    private function restoreFiles(string $zipPath): int
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            $this->error('Archive could not be reopened for extraction.');

            return self::FAILURE;
        }

        $password = config('backup.backup.password');

        if (is_string($password) && $password !== '') {
            $zip->setPassword($password);
        }

        $restored = 0;
        $skipped = 0;
        $failed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);

            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }

            $mapping = $this->mapToLocalPath($entry);

            if ($mapping === null) {
                $skipped++;

                continue;
            }

            [$diskName, $relativePath] = $mapping;

            $stream = $zip->getStreamIndex($i);

            if ($stream === false) {
                $this->warn("  [failed] {$entry} — could not open entry stream");
                $failed++;

                continue;
            }

            try {
                $written = Storage::disk($diskName)->writeStream($relativePath, $stream);
            } finally {
                fclose($stream);
            }

            if ($written) {
                $restored++;
            } else {
                $this->warn("  [failed] {$entry} — write to disk '{$diskName}' failed");
                $failed++;
            }
        }

        $zip->close();

        $this->info("Media restore complete: {$restored} restored, {$skipped} skipped, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Map a zip entry to [disk, relative path] on the local filesystem, or
     * null when the entry is not restorable media. Entries are stored rooted
     * at the deploy directory (e.g. "app/storage/app/public/..." on the /app
     * production image), so the storage/app/{public,private}/ marker is matched
     * anywhere in the path.
     *
     * @return array{0: string, 1: string}|null
     */
    private function mapToLocalPath(string $entry): ?array
    {
        $normalized = ltrim(str_replace('\\', '/', $entry), '/');

        if (str_ends_with($normalized, '/')) {
            return null;
        }

        foreach ([self::PUBLIC_PREFIX => 'public', self::PRIVATE_PREFIX => 'local'] as $prefix => $diskName) {
            $position = strpos($normalized, $prefix);

            if ($position === false) {
                continue;
            }

            $relativePath = substr($normalized, $position + strlen($prefix));

            if ($relativePath === '' || $relativePath === false) {
                return null;
            }

            $segments = explode('/', $relativePath);

            if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
                return null; // path traversal — never extracted
            }

            // Skip archived backup zips themselves (the local backup directory
            // lives inside the private disk).
            if ($diskName === 'local' && str_starts_with($relativePath, (string) config('backup.backup.name').'/')) {
                return null;
            }

            return [$diskName, $relativePath];
        }

        return null;
    }

    private function extractEntry(string $zipPath, string $entry, string $targetPath): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return false;
        }

        $password = config('backup.backup.password');

        if (is_string($password) && $password !== '') {
            $zip->setPassword($password);
        }

        $index = $zip->locateName($entry, ZipArchive::FL_NODIR);

        if ($index === false) {
            $zip->close();

            return false;
        }

        $stream = $zip->getStreamIndex($index);

        if ($stream === false) {
            $zip->close();

            return false;
        }

        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            fclose($stream);
            $zip->close();

            return false;
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);
        $zip->close();

        return filesize($targetPath) > 0;
    }

    private function gunzipFile(string $source, string $target): bool
    {
        $in = @gzopen($source, 'rb');

        if ($in === false) {
            return false;
        }

        $out = fopen($target, 'wb');

        if ($out === false) {
            gzclose($in);

            return false;
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 262144);

            if ($chunk === false || fwrite($out, $chunk) === false) {
                gzclose($in);
                fclose($out);

                return false;
            }
        }

        gzclose($in);
        fclose($out);

        return true;
    }

    private function openArchiveLocally(string $diskName, string $fileName): ?string
    {
        if ($this->isLocalArtifact($diskName, $fileName)) {
            $path = Storage::disk($diskName)->path($fileName);

            return is_file($path) ? $path : null;
        }

        $disk = Storage::disk($diskName);

        try {
            $readStream = $disk->readStream($fileName);
        } catch (\Throwable) {
            return null;
        }

        if ($readStream === false || $readStream === null) {
            return null;
        }

        $tempPath = storage_path('app/backup-temp/restore-'.sha1($diskName.'|'.$fileName).'.zip');
        @mkdir(dirname($tempPath), 0775, true);

        $writeStream = @fopen($tempPath, 'w+b');

        if ($writeStream === false) {
            fclose($readStream);

            return null;
        }

        stream_copy_to_stream($readStream, $writeStream);
        fclose($readStream);
        fclose($writeStream);

        return $tempPath;
    }

    private function isLocalArtifact(string $diskName, string $fileName): bool
    {
        $adapter = Storage::disk($diskName)->getAdapter();

        return $adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter;
    }

    private function resolveDisk(): string
    {
        $explicit = $this->option('disk');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return (string) (BackupArtifactVerifier::destinationDiskNames()[0] ?? 'local');
    }

    private function resolveBinary(string $name): ?string
    {
        $output = @shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null');

        if (is_string($output) && trim($output) !== '') {
            return trim($output);
        }

        return null;
    }
}

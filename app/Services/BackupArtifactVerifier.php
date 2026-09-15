<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupArtifactVerifier
{
    /**
     * Byte window sampled from the start of a database dump to confirm it is
     * structurally plausible (mysqldump emits CREATE TABLE well within this).
     */
    private const DUMP_SAMPLE_BYTES = 262144;

    public function verifyNewestOnDisk(string $diskName): BackupArtifactReport
    {
        $file = $this->newestBackupFile($diskName);

        if ($file === null) {
            return new BackupArtifactReport(
                disk: $diskName,
                errors: ["no backup zip files found on disk '{$diskName}' under '".config('backup.backup.name')."'"],
            );
        }

        return $this->verify($diskName, $file);
    }

    public function verify(string $diskName, string $fileName): BackupArtifactReport
    {
        $disk = Storage::disk($diskName);

        if (! $disk->exists($fileName)) {
            return new BackupArtifactReport(
                disk: $diskName,
                file: $fileName,
                errors: ["backup file '{$fileName}' does not exist on disk '{$diskName}'"],
            );
        }

        $sizeBytes = (int) $disk->size($fileName);
        $lastModified = (int) $disk->lastModified($fileName);

        $localPath = $this->materialize($diskName, $fileName);

        if ($localPath === null) {
            return new BackupArtifactReport(
                disk: $diskName,
                file: $fileName,
                errors: ["could not obtain a readable local copy of '{$fileName}' from disk '{$diskName}'"],
                sizeBytes: $sizeBytes,
                lastModified: $lastModified,
            );
        }

        try {
            return $this->verifyLocalZip($diskName, $fileName, $localPath, $sizeBytes, $lastModified);
        } finally {
            if ($localPath !== $this->onDiskPath($diskName, $fileName)) {
                @unlink($localPath);
            }
        }
    }

    private function verifyLocalZip(string $diskName, string $fileName, string $localPath, int $sizeBytes, int $lastModified): BackupArtifactReport
    {
        $zip = new ZipArchive;
        $resultCode = $zip->open($localPath);

        if ($resultCode !== true) {
            return new BackupArtifactReport(
                disk: $diskName,
                file: $fileName,
                errors: ['archive is not a readable zip (ZipArchive code '.$resultCode.') — upload was truncated, corrupt, or not a backup zip'],
                sizeBytes: $sizeBytes,
                lastModified: $lastModified,
            );
        }

        $errors = [];
        $warnings = [];

        $password = config('backup.backup.password');
        $encrypted = false;
        $hasFileEntries = false;

        if ($zip->numFiles > 0) {
            $firstStat = $zip->statIndex(0);
            $encrypted = is_array($firstStat) && (($firstStat['encryption_method'] ?? 0) !== 0);
        }

        if ($password !== null && $password !== '') {
            $zip->setPassword($password);
        } elseif ($encrypted) {
            $errors[] = 'archive entries are encrypted but no BACKUP_PASSWORD is configured — the artifact cannot be decrypted';
        }

        $dbDumpEntries = 0;
        $mediaEntries = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if (! is_array($stat)) {
                continue;
            }

            $name = (string) $stat['name'];

            if (str_ends_with($name, '/') && (int) $stat['size'] === 0) {
                continue; // directory entry
            }

            $hasFileEntries = true;

            if ($this->isDbDumpEntry($name)) {
                $dbDumpEntries++;

                if ($dbDumpEntries === 1) {
                    $sample = $zip->getFromIndex($i, self::DUMP_SAMPLE_BYTES);

                    if ($sample === false || $sample === '') {
                        $errors[] = "database dump entry '{$name}' is unreadable (decryption failed or entry empty)";
                    } elseif (! str_contains(strtolower($sample), 'create table')) {
                        $errors[] = "database dump entry '{$name}' contains no CREATE TABLE within the first "
                            .self::DUMP_SAMPLE_BYTES.' bytes — not a plausible schema dump';
                    }
                }

                continue;
            }

            if ($this->isMediaEntry($name)) {
                $mediaEntries++;
            }
        }

        if (! $hasFileEntries) {
            $errors[] = 'archive contains no file entries at all';
        } else {
            if ($dbDumpEntries === 0 && $mediaEntries === 0) {
                $errors[] = 'archive contains neither a db-dumps/ dump nor storage/app media entries — nothing recoverable inside';
            }

            if ($dbDumpEntries > 1) {
                $warnings[] = "archive contains {$dbDumpEntries} database dump entries (expected at most one)";
            }
        }

        $zip->close();

        return new BackupArtifactReport(
            disk: $diskName,
            file: $fileName,
            errors: $errors,
            warnings: $warnings,
            sizeBytes: $sizeBytes,
            lastModified: $lastModified,
            dbDumpEntries: $dbDumpEntries,
            mediaEntries: $mediaEntries,
            encrypted: $encrypted,
        );
    }

    /**
     * The configured backup destination disk names (BACKUP_DISKS), normalized.
     *
     * @return list<string>
     */
    public static function destinationDiskNames(): array
    {
        $disks = config('backup.backup.destination.disks', 'local');

        if (! is_array($disks)) {
            $disks = explode(',', (string) $disks);
        }

        return array_values(array_filter(array_map('trim', $disks)));
    }

    /**
     * @return list<string>
     */
    public function availableBackups(string $diskName): array
    {
        $disk = Storage::disk($diskName);
        $dir = (string) config('backup.backup.name');

        try {
            $files = $disk->files($dir);
        } catch (\Throwable) {
            return [];
        }

        $zips = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.zip')));
        usort($zips, fn ($a, $b) => $disk->lastModified($b) <=> $disk->lastModified($a));

        return $zips;
    }

    public function newestBackupFile(string $diskName): ?string
    {
        $all = $this->availableBackups($diskName);

        return $all[0] ?? null;
    }

    private function isDbDumpEntry(string $entryName): bool
    {
        return str_contains($entryName, 'db-dumps/')
            && (str_ends_with($entryName, '.sql') || str_ends_with($entryName, '.sql.gz'));
    }

    private function isMediaEntry(string $entryName): bool
    {
        return str_contains($entryName, 'storage/app/public/')
            || str_contains($entryName, 'storage/app/private/');
    }

    /**
     * ZipArchive needs a real file. Remote disks are streamed to a temporary
     * copy; local disks are used in place.
     */
    private function materialize(string $diskName, string $fileName): ?string
    {
        $onDisk = $this->onDiskPath($diskName, $fileName);

        if ($onDisk !== null && is_file($onDisk)) {
            return $onDisk;
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

        $tempPath = storage_path('app/backup-temp/verify-'.sha1($diskName.'|'.$fileName).'.zip');
        @mkdir(dirname($tempPath), 0775, true);

        $writeStream = @fopen($tempPath, 'w+b');

        if ($writeStream === false) {
            fclose($readStream);

            return null;
        }

        stream_copy_to_stream($readStream, $writeStream);
        fclose($readStream);
        rewind($writeStream);
        fclose($writeStream);

        return is_file($tempPath) ? $tempPath : null;
    }

    private function onDiskPath(string $diskName, string $fileName): ?string
    {
        $disk = Storage::disk($diskName);
        $adapter = $disk->getAdapter();

        if (! $adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
            return null;
        }

        return rtrim($disk->path(''), '/').'/'.ltrim($fileName, '/');
    }
}

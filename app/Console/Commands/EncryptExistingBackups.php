<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupArchiveCipher;
use App\Services\BackupArtifactVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * One-off remediation: encrypt archives that were written in plaintext while
 * the production libzip silently ignored zip-level AES.
 *
 * Safe by construction: dry-run unless --execute; each archive is encrypted to a
 * temp file, decrypt-verified, uploaded over the original, then re-verified.
 * Spatie dates backups from the filename (Y-m-d-H-i-s), so rewriting a file
 * does not disturb retention/cleanup.
 */
class EncryptExistingBackups extends Command
{
    protected $signature = 'exospace:backup:encrypt-existing
        {--disk=* : Disk(s) to process (default: all BACKUP_DISKS)}
        {--execute : Actually rewrite archives (default is a dry run)}';

    protected $description = 'Encrypt backup archives that were stored in plaintext (dry run unless --execute)';

    public function handle(BackupArtifactVerifier $verifier): int
    {
        $passphrase = BackupArchiveCipher::passphrase();

        if ($passphrase === null) {
            $this->error('BACKUP_PASSWORD is not configured — nothing to encrypt with.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $disks = array_values(array_filter((array) $this->option('disk')))
            ?: BackupArtifactVerifier::destinationDiskNames();

        $cipher = new BackupArchiveCipher;
        $failures = 0;
        $encrypted = 0;
        $pending = 0;
        $already = 0;

        foreach ($disks as $diskName) {
            $this->info("Disk '{$diskName}':");

            foreach ($verifier->availableBackups($diskName) as $file) {
                $local = $this->localCopy($diskName, $file);

                if ($local === null) {
                    $this->error("  {$file}: could not read");
                    $failures++;

                    continue;
                }

                [$path, $isTemp] = $local;

                try {
                    if (BackupArchiveCipher::isEncrypted($path)) {
                        $already++;

                        continue;
                    }

                    if (! $execute) {
                        $this->line("  {$file}: plaintext — would encrypt");
                        $pending++;

                        continue;
                    }

                    $this->encryptOne($cipher, $diskName, $file, $path, $passphrase);
                    $this->line("  {$file}: encrypted and re-verified");
                    $encrypted++;
                } catch (Throwable $e) {
                    $this->error("  {$file}: FAILED — ".$e->getMessage());
                    Log::error('exospace:backup:encrypt-existing failed', ['disk' => $diskName, 'file' => $file, 'error' => $e->getMessage()]);
                    $failures++;
                } finally {
                    if ($isTemp) {
                        @unlink($path);
                    }
                }
            }
        }

        $this->line('');
        $this->line("Already encrypted: {$already}");

        if ($execute) {
            $this->line("Encrypted now: {$encrypted}, failed: {$failures}");
        } else {
            $this->line("Plaintext archives that would be encrypted: {$pending}, unreadable: {$failures}");
            $this->info('DRY RUN — nothing was modified. Re-run with --execute to apply.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function encryptOne(BackupArchiveCipher $cipher, string $diskName, string $file, string $plainPath, string $passphrase): void
    {
        $disk = Storage::disk($diskName);
        $encPath = storage_path('app/backup-temp/enc-'.sha1($diskName.'|'.$file).'.zip');
        @mkdir(dirname($encPath), 0775, true);

        try {
            $cipher->encryptFile($plainPath, $encPath, $passphrase);
            $cipher->decryptFile($encPath, null, $passphrase); // verify before touching the original

            $stream = fopen($encPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('cannot reopen encrypted temp file');
            }

            try {
                $disk->writeStream($file, $stream);
            } finally {
                fclose($stream);
            }

            $expected = (int) filesize($encPath);
            $actual = (int) $disk->size($file);

            if ($actual !== $expected) {
                throw new RuntimeException("size mismatch after upload (expected {$expected}, disk reports {$actual})");
            }

            $report = app(BackupArtifactVerifier::class)->verify($diskName, $file);

            if (! $report->passes()) {
                throw new RuntimeException('post-upload verification failed: '.$report->problemSummary());
            }
        } finally {
            @unlink($encPath);
        }
    }

    /**
     * @return array{0: string, 1: bool}|null [path, isTemporaryCopy]
     */
    private function localCopy(string $diskName, string $file): ?array
    {
        $disk = Storage::disk($diskName);

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $path = $disk->path($file);

            return is_file($path) ? [$path, false] : null;
        }

        try {
            $read = $disk->readStream($file);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($read)) {
            return null;
        }

        $tmp = storage_path('app/backup-temp/src-'.sha1($diskName.'|'.$file).'.zip');
        @mkdir(dirname($tmp), 0775, true);
        $write = @fopen($tmp, 'wb');

        if ($write === false) {
            fclose($read);

            return null;
        }

        stream_copy_to_stream($read, $write);
        fclose($read);
        fclose($write);

        return [$tmp, true];
    }
}

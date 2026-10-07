<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\BackupArchiveCipher;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupZipWasCreated;
use Throwable;

/**
 * Encrypts the finished backup zip in place, before spatie copies it to the
 * destination disks.
 *
 * FAIL CLOSED: spatie dispatches BackupZipWasCreated inside rescue(), which
 * swallows listener exceptions — a bare throw would let the plaintext zip be
 * uploaded anyway. So on any failure the plaintext zip is deleted first; the
 * copy step then finds nothing to upload and the run is reported as failed.
 *
 * Wiring (AppServiceProvider):
 *  - Event::listen(BackupZipWasCreated) covers the normal path.
 *  - This class is also bound over spatie's own EncryptBackupArchive, which
 *    spatie calls directly when notifications are disabled.
 *
 * Idempotent, so running twice is harmless.
 */
class EncryptBackupArchive
{
    public function handle(BackupZipWasCreated $event): void
    {
        $passphrase = BackupArchiveCipher::passphrase();

        if ($passphrase === null) {
            return; // no BACKUP_PASSWORD: unencrypted by policy (preflight warns)
        }

        try {
            (new BackupArchiveCipher)->encryptInPlace($event->pathToZip, $passphrase);
        } catch (Throwable $e) {
            @unlink($event->pathToZip);

            Log::critical('Backup archive encryption failed; plaintext archive deleted so it cannot be uploaded.', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

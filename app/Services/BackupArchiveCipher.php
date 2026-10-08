<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Authenticated, streaming encryption for finished backup archives.
 *
 * Why this exists: the production PHP (Nix php 8.2.27) ships a libzip built
 * without crypto, so spatie's zip-level AES-256 silently wrote PLAINTEXT zips
 * (ZipArchive::EM_AES_256 is defined, but setEncryptionIndex has no effect).
 * libsodium and openssl are always present, so the finished archive is
 * encrypted as a whole instead.
 *
 * The encrypted file keeps its ".zip" name on purpose: spatie's cleanup and
 * monitor only recognise "*.zip" in the backup directory.
 *
 * File format (all integers big-endian):
 *   8  bytes  magic "EXOBAK01"
 *   4  bytes  argon2id opslimit
 *   4  bytes  argon2id memlimit (bytes)
 *   16 bytes  argon2id salt
 *   24 bytes  libsodium secretstream header
 *   repeated: 4-byte ciphertext length, then ciphertext
 *             (XChaCha20-Poly1305 secretstream; the last record carries TAG_FINAL,
 *              so truncation is detected)
 *
 * scripts/backup-decrypt.php implements the same format with no Laravel
 * dependency, for disaster recovery on a bare machine.
 */
final class BackupArchiveCipher
{
    public const MAGIC = 'EXOBAK01';

    public const DEFAULT_CHUNK_BYTES = 1048576;

    /** Upper bound accepted when reading, so a tampered length cannot force a huge allocation. */
    private const MAX_CHUNK_BYTES = 16777216;

    private const HEADER_BYTES = 8 + 4 + 4 + 16 + 24;

    private const MAX_MEMLIMIT_BYTES = 1073741824;

    public function __construct(private readonly int $chunkBytes = self::DEFAULT_CHUNK_BYTES) {}

    /**
     * The configured archive passphrase (BACKUP_PASSWORD), or null when unset.
     *
     * backup.backup.password is deliberately null in config/backup.php so that
     * spatie does not attempt its own (unsupported) zip encryption; the value
     * lives in backup.backup.archive_passphrase. An explicit non-empty
     * backup.backup.password still wins, which keeps older callers and tests
     * that override it working.
     */
    public static function passphrase(): ?string
    {
        foreach (['backup.backup.password', 'backup.backup.archive_passphrase'] as $key) {
            $value = config($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * Encrypt $path in place. The original is replaced only after the
     * encrypted copy has been written AND fully decrypt-verified.
     */
    public function encryptInPlace(string $path, string $passphrase): void
    {
        if (self::isEncrypted($path)) {
            return; // idempotent
        }

        $tmp = $path.'.enc-tmp';

        try {
            $this->encryptFile($path, $tmp, $passphrase);
            $this->decryptFile($tmp, null, $passphrase); // verify-only pass

            if (! @rename($tmp, $path)) {
                throw new RuntimeException("could not move encrypted archive into place at '{$path}'");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function encryptFile(string $source, string $target, string $passphrase): void
    {
        $this->assertSodium();
        $this->assertDistinctFiles($source, $target);

        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException("cannot read '{$source}'");
        }

        $out = @fopen($target, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException("cannot write '{$target}'");
        }

        $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);

        try {
            $this->writeAll($out, self::MAGIC.pack('NN', $opslimit, $memlimit).$salt.$streamHeader);

            $current = $this->readChunk($in);

            while (true) {
                $next = $current === '' ? '' : $this->readChunk($in);
                $isLast = $next === '';

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $current,
                    '',
                    $isLast ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );

                $this->writeAll($out, pack('N', strlen($cipher)).$cipher);

                if ($isLast) {
                    break;
                }

                $current = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypt $source to $target (or only verify it when $target is null).
     *
     * @throws RuntimeException on wrong passphrase, corruption, tampering or truncation
     */
    public function decryptFile(string $source, ?string $target, string $passphrase): void
    {
        $this->assertSodium();

        if ($target !== null) {
            $this->assertDistinctFiles($source, $target);
        }

        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException("cannot read '{$source}'");
        }

        $out = null;

        try {
            $header = $this->readExactly($in, self::HEADER_BYTES);

            if ($header === null || substr($header, 0, 8) !== self::MAGIC) {
                throw new RuntimeException('not an Exospace encrypted backup (bad magic/short header)');
            }

            /** @var array{ops: int, mem: int} $limits */
            $limits = unpack('Nops/Nmem', substr($header, 8, 8));

            if ($limits['ops'] < 1 || $limits['ops'] > 32 || $limits['mem'] < 8192 || $limits['mem'] > self::MAX_MEMLIMIT_BYTES) {
                throw new RuntimeException('encrypted backup header has implausible key-derivation parameters');
            }

            $key = sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                $passphrase,
                substr($header, 16, 16),
                $limits['ops'],
                $limits['mem'],
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 32, 24), $key);
            sodium_memzero($key);

            if ($target !== null) {
                $out = @fopen($target, 'wb');

                if ($out === false) {
                    throw new RuntimeException("cannot write '{$target}'");
                }
            }

            $sawFinal = false;
            $first = true;

            while (true) {
                $lengthBytes = $this->readExactly($in, 4);

                if ($lengthBytes === null) {
                    break;
                }

                if ($sawFinal) {
                    throw new RuntimeException('unexpected data after the final record — archive is corrupt');
                }

                /** @var array{len: int} $length */
                $length = unpack('Nlen', $lengthBytes);

                if ($length['len'] < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length['len'] > self::MAX_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('record length out of range — archive is corrupt');
                }

                $cipher = $this->readExactly($in, $length['len']);

                if ($cipher === null) {
                    throw new RuntimeException('archive is truncated mid-record');
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($result === false) {
                    throw new RuntimeException($first
                        ? 'decryption failed — wrong BACKUP_PASSWORD or corrupted archive'
                        : 'authentication failed — archive is corrupted or was tampered with');
                }

                [$plain, $tag] = $result;
                $first = false;

                if ($out !== null) {
                    $this->writeAll($out, $plain);
                }

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                }
            }

            if (! $sawFinal) {
                throw new RuntimeException('archive is truncated — final record missing');
            }
        } catch (RuntimeException $e) {
            if ($target !== null && is_resource($out)) {
                fclose($out);
                $out = null;
                @unlink($target);
            }

            throw $e;
        } finally {
            fclose($in);

            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * Opening the target for writing truncates it, so it must never be the
     * file being read (this once emptied a downloaded R2 copy mid-verify).
     */
    private function assertDistinctFiles(string $source, string $target): void
    {
        $a = realpath($source);
        $b = realpath($target);

        if ($source === $target || ($a !== false && $a === $b)) {
            throw new RuntimeException('source and target must be different files');
        }
    }

    private function assertSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')) {
            throw new RuntimeException('the sodium PHP extension is required for backup encryption');
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readChunk($handle): string
    {
        $data = '';

        while (strlen($data) < $this->chunkBytes && ! feof($handle)) {
            $piece = fread($handle, $this->chunkBytes - strlen($data));

            if ($piece === false) {
                throw new RuntimeException('read error while encrypting');
            }

            if ($piece === '') {
                break;
            }

            $data .= $piece;
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function readExactly($handle, int $bytes): ?string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            $piece = fread($handle, $bytes - strlen($data));

            if ($piece === false || $piece === '') {
                return $data === '' ? null : throw new RuntimeException('unexpected end of file — archive is truncated');
            }

            $data .= $piece;
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $n = fwrite($handle, substr($data, $written));

            if ($n === false || $n === 0) {
                throw new RuntimeException('write failed (disk full?)');
            }

            $written += $n;
        }
    }
}

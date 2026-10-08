<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\EncryptBackupArchive;
use App\Services\BackupArchiveCipher;
use App\Services\BackupArtifactVerifier;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Events\BackupZipWasCreated;
use Tests\TestCase;
use ZipArchive;

class BackupArchiveEncryptionTest extends TestCase
{
    private const BACKUP_DIR = 'Exospace Backup';

    private const PASS = 'test-backup-passphrase';

    private string $diskRoot;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.backup.password' => null,
            'backup.backup.archive_passphrase' => self::PASS,
        ]);

        Storage::fake('local');
        Storage::fake('public');

        $this->diskRoot = Storage::disk('local')->path('');
        $this->scratch = sys_get_temp_dir().'/exo-enc-'.uniqid();
        mkdir($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->scratch);

        parent::tearDown();
    }

    public function test_spatie_zip_level_encryption_is_disabled_in_config(): void
    {
        $config = require config_path('backup.php');

        $this->assertNull($config['backup']['password'], 'spatie must not attempt zip-level AES; libzip in prod cannot do it');
    }

    public function test_passphrase_comes_from_archive_passphrase_config(): void
    {
        $this->assertSame(self::PASS, BackupArchiveCipher::passphrase());

        config(['backup.backup.archive_passphrase' => null]);
        $this->assertNull(BackupArchiveCipher::passphrase());
    }

    public function test_round_trip_across_chunk_boundaries(): void
    {
        foreach ([0, 1, 99, 100, 101, 5000] as $size) {
            $plain = $this->scratch.'/plain.bin';
            file_put_contents($plain, $size > 0 ? random_bytes($size) : '');

            $cipher = new BackupArchiveCipher(100);
            $cipher->encryptFile($plain, $this->scratch.'/enc.bin', self::PASS);
            $cipher->decryptFile($this->scratch.'/enc.bin', $this->scratch.'/out.bin', self::PASS);

            $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->scratch.'/out.bin'), "size {$size}");
        }
    }

    public function test_cipher_refuses_to_write_over_its_own_source(): void
    {
        $plain = $this->scratch.'/plain.bin';
        file_put_contents($plain, 'secret-contents');
        $cipher = new BackupArchiveCipher;
        $cipher->encryptFile($plain, $this->scratch.'/enc.bin', self::PASS);

        try {
            $cipher->decryptFile($this->scratch.'/enc.bin', $this->scratch.'/enc.bin', self::PASS);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different files', $e->getMessage());
        }

        $this->assertGreaterThan(0, filesize($this->scratch.'/enc.bin'), 'the source must be left intact');
        $cipher->decryptFile($this->scratch.'/enc.bin', $this->scratch.'/out.bin', self::PASS);
        $this->assertSame('secret-contents', file_get_contents($this->scratch.'/out.bin'));
    }

    public function test_verifier_decrypted_temp_path_differs_from_the_downloaded_copy(): void
    {
        $source = file_get_contents(base_path('app/Services/BackupArtifactVerifier.php'));

        $this->assertStringContainsString('backup-temp/verify-dec-', $source);
        $this->assertSame(1, substr_count($source, "backup-temp/verify-'"), 'only materialize() may use the plain verify- prefix');
    }

    public function test_wrong_passphrase_is_rejected_and_leaves_no_output(): void
    {
        $plain = $this->scratch.'/plain.bin';
        file_put_contents($plain, 'secret');
        $cipher = new BackupArchiveCipher;
        $cipher->encryptFile($plain, $this->scratch.'/enc.bin', self::PASS);

        $this->expectException(RuntimeException::class);

        try {
            $cipher->decryptFile($this->scratch.'/enc.bin', $this->scratch.'/out.bin', 'wrong');
        } finally {
            $this->assertFileDoesNotExist($this->scratch.'/out.bin');
        }
    }

    public function test_truncated_archive_is_detected(): void
    {
        $plain = $this->scratch.'/plain.bin';
        file_put_contents($plain, random_bytes(5000));
        $cipher = new BackupArchiveCipher(1000);
        $cipher->encryptFile($plain, $this->scratch.'/enc.bin', self::PASS);

        $raw = (string) file_get_contents($this->scratch.'/enc.bin');
        // cut exactly after the header and the first record: valid prefix, no final record
        file_put_contents($this->scratch.'/cut.bin', substr($raw, 0, 56 + 4 + 1017));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        $cipher->decryptFile($this->scratch.'/cut.bin', null, self::PASS);
    }

    public function test_tampered_archive_is_detected(): void
    {
        $plain = $this->scratch.'/plain.bin';
        file_put_contents($plain, random_bytes(5000));
        $cipher = new BackupArchiveCipher(1000);
        $cipher->encryptFile($plain, $this->scratch.'/enc.bin', self::PASS);

        $raw = (string) file_get_contents($this->scratch.'/enc.bin');
        $raw[200] = chr(ord($raw[200]) ^ 1);
        file_put_contents($this->scratch.'/bad.bin', $raw);

        $this->expectException(RuntimeException::class);

        $cipher->decryptFile($this->scratch.'/bad.bin', null, self::PASS);
    }

    public function test_listener_encrypts_the_zip_in_place_and_is_idempotent(): void
    {
        $zip = $this->makePlainZip($this->scratch.'/b.zip', ['db-dumps/mysql-exospace.sql' => "CREATE TABLE t (id INT);\n"]);

        (new EncryptBackupArchive)->handle(new BackupZipWasCreated($zip));

        $this->assertTrue(BackupArchiveCipher::isEncrypted($zip));
        $this->assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($zip));

        $hash = hash_file('sha256', $zip);
        (new EncryptBackupArchive)->handle(new BackupZipWasCreated($zip));
        $this->assertSame($hash, hash_file('sha256', $zip), 'a second run must not re-encrypt');
    }

    public function test_listener_fails_closed_by_deleting_the_plaintext_zip(): void
    {
        $zip = $this->makePlainZip($this->scratch.'/b.zip', ['db-dumps/mysql-exospace.sql' => "CREATE TABLE t (id INT);\n"]);

        // Make the encrypted temp target unwritable so encryption throws.
        mkdir($zip.'.enc-tmp');

        try {
            (new EncryptBackupArchive)->handle(new BackupZipWasCreated($zip));
            $this->fail('expected the listener to rethrow');
        } catch (RuntimeException) {
            // expected
        } finally {
            @rmdir($zip.'.enc-tmp');
        }

        $this->assertFileDoesNotExist($zip, 'a failed encryption must never leave a plaintext archive to be uploaded');
    }

    public function test_listener_does_nothing_without_a_passphrase(): void
    {
        config(['backup.backup.archive_passphrase' => null]);
        $zip = $this->makePlainZip($this->scratch.'/b.zip', ['db-dumps/mysql-exospace.sql' => "CREATE TABLE t (id INT);\n"]);

        (new EncryptBackupArchive)->handle(new BackupZipWasCreated($zip));

        $this->assertFalse(BackupArchiveCipher::isEncrypted($zip));
    }

    public function test_spatie_encrypt_class_resolves_to_our_implementation(): void
    {
        $this->assertInstanceOf(
            EncryptBackupArchive::class,
            app(\Spatie\Backup\Listeners\EncryptBackupArchive::class),
        );
    }

    public function test_verifier_accepts_a_sodium_encrypted_archive(): void
    {
        $this->storeEncryptedBackup('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "-- dump\nCREATE TABLE `users` (`id` bigint);\n",
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertTrue($report->encrypted);
        $this->assertSame(1, $report->dbDumpEntries);
    }

    public function test_verifier_fails_when_the_passphrase_no_longer_matches(): void
    {
        $this->storeEncryptedBackup('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ]);

        config(['backup.backup.archive_passphrase' => 'rotated-and-forgot-the-old-one']);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('could not be decrypted', $report->problemSummary());
    }

    public function test_verifier_still_flags_a_plaintext_archive_when_a_passphrase_is_configured(): void
    {
        @mkdir($this->diskRoot.self::BACKUP_DIR, 0775, true);
        $this->makePlainZip($this->diskRoot.self::BACKUP_DIR.'/2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('not encrypted although BACKUP_PASSWORD is configured', $report->problemSummary());
    }

    public function test_restore_works_from_a_sodium_encrypted_archive(): void
    {
        $this->storeEncryptedBackup('2026-01-15-01-30-00.zip', [
            'app/storage/app/public/galleries/demo/art.png' => 'png-bytes-here',
        ]);

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
            '--confirm' => 'RESTORE',
            '--only-files' => true,
        ])->assertExitCode(0);

        $this->assertSame('png-bytes-here', Storage::disk('public')->get('galleries/demo/art.png'));
        $this->assertSame([], glob(storage_path('app/backup-temp/restore-dec-*.zip')) ?: [], 'decrypted temp zip must be removed');
    }

    public function test_encrypt_existing_dry_run_changes_nothing_and_execute_encrypts(): void
    {
        @mkdir($this->diskRoot.self::BACKUP_DIR, 0775, true);
        $path = $this->diskRoot.self::BACKUP_DIR.'/2026-01-15-01-00-00.zip';
        $this->makePlainZip($path, ['db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n"]);
        $before = hash_file('sha256', $path);

        config(['backup.backup.destination.disks' => ['local']]);

        $this->artisan('exospace:backup:encrypt-existing', ['--disk' => ['local']])->assertExitCode(0);
        $this->assertSame($before, hash_file('sha256', $path), 'dry run must not modify archives');

        $this->artisan('exospace:backup:encrypt-existing', ['--disk' => ['local'], '--execute' => true])->assertExitCode(0);
        $this->assertTrue(BackupArchiveCipher::isEncrypted($path));

        $report = app(BackupArtifactVerifier::class)->verify('local', self::BACKUP_DIR.'/2026-01-15-01-00-00.zip');
        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertTrue($report->encrypted);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makePlainZip(string $path, array $entries): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function storeEncryptedBackup(string $fileName, array $entries): void
    {
        @mkdir($this->diskRoot.self::BACKUP_DIR, 0775, true);
        $path = $this->makePlainZip($this->diskRoot.self::BACKUP_DIR.'/'.$fileName, $entries);

        (new BackupArchiveCipher)->encryptInPlace($path, self::PASS);
    }
}

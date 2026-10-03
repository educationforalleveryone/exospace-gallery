<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BackupArtifactVerifier;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupArtifactVerifierTest extends TestCase
{
    private const BACKUP_DIR = 'Exospace Backup';

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config(['backup.backup.password' => 'test-backup-password']);

        Storage::fake('local');

        $this->diskRoot = Storage::disk('local')->path('');
    }

    public function test_valid_db_backup_passes_verification(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "-- MySQL dump 10.19\nCREATE TABLE `users` (`id` bigint unsigned NOT NULL AUTO_INCREMENT);\nINSERT INTO `users` VALUES (1);\n",
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertSame(1, $report->dbDumpEntries);
        $this->assertTrue($report->encrypted);
    }

    public function test_valid_files_backup_passes_verification(): void
    {
        $this->makeBackupZip('2026-01-19-01-30-00.zip', [
            'app/storage/app/public/galleries/demo/art.png' => 'png-bytes-here',
            'app/storage/app/private/invoices/inv-1.pdf' => 'pdf-bytes-here',
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertSame(2, $report->mediaEntries);
        $this->assertSame(0, $report->dbDumpEntries);
    }

    public function test_truncated_or_corrupt_zip_fails(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ]);

        $path = $this->diskRoot.self::BACKUP_DIR.'/2026-01-15-01-00-00.zip';
        file_put_contents($path, substr(file_get_contents($path), 0, 40));

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('not a readable zip', $report->problemSummary());
    }

    public function test_archive_encrypted_with_wrong_password_fails(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ], 'a-different-password');

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('unreadable', $report->problemSummary());
    }

    public function test_empty_archive_fails(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', []);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('no file entries', $report->problemSummary());
    }

    public function test_dump_without_schema_fails_plausibility_check(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "-- some header comment, no tables\n",
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('no CREATE TABLE', $report->problemSummary());
    }

    public function test_archive_with_neither_dumps_nor_media_fails(): void
    {
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'unrelated/thing.txt' => 'not exospace data',
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('nothing recoverable', $report->problemSummary());
    }

    public function test_newest_backup_is_selected_by_modification_time(): void
    {
        $this->makeBackupZip('2026-01-10-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE older (id INT);\n",
        ]);
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE newer (id INT);\n",
        ]);

        touch(
            $this->diskRoot.self::BACKUP_DIR.'/2026-01-10-01-00-00.zip',
            now()->subDays(5)->getTimestamp(),
        );

        $verifier = app(BackupArtifactVerifier::class);

        $this->assertSame(
            self::BACKUP_DIR.'/2026-01-15-01-00-00.zip',
            $verifier->newestBackupFile('local'),
        );

        $report = $verifier->verifyNewestOnDisk('local');
        $this->assertTrue($report->passes(), $report->problemSummary());
    }

    public function test_missing_backup_directory_fails_with_clear_error(): void
    {
        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('no backup zip files found', $report->problemSummary());
        $this->assertNull($report->file);
    }

    public function test_unencrypted_archive_passes_when_no_password_configured(): void
    {
        config(['backup.backup.password' => null]);

        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ], encrypt: false);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertFalse($report->encrypted);
    }

    public function test_unencrypted_archive_fails_when_encryption_is_configured(): void
    {
        // A plaintext archive must never look healthy: BACKUP_PASSWORD is set
        // in production, so an unencrypted artifact means the encryption step
        // was skipped and the dump would sit readable in the R2 bucket.
        $this->makeBackupZip('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ], encrypt: false);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertFalse($report->passes());
        $this->assertStringContainsString('not encrypted although BACKUP_PASSWORD is configured', $report->problemSummary());
    }

    public function test_encryption_is_detected_even_when_a_directory_entry_comes_first(): void
    {
        $this->makeBackupZipWithLeadingDirectory('2026-01-15-01-00-00.zip', [
            'db-dumps/mysql-exospace.sql' => "CREATE TABLE users (id INT);\n",
        ]);

        $report = app(BackupArtifactVerifier::class)->verifyNewestOnDisk('local');

        $this->assertTrue($report->passes(), $report->problemSummary());
        $this->assertTrue($report->encrypted, 'encryption must be read from the first file entry, not statIndex(0)');
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makeBackupZip(string $fileName, array $entries, string $password = 'test-backup-password', bool $encrypt = true): void
    {
        @mkdir($this->diskRoot.self::BACKUP_DIR, 0775, true);

        $zip = new ZipArchive;
        $zip->open($this->diskRoot.self::BACKUP_DIR.'/'.$fileName, ZipArchive::CREATE);

        if ($entries === []) {
            $zip->addEmptyDir('placeholder');
        }

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        if ($encrypt) {
            $zip->setPassword($password);

            foreach (range(0, $zip->numFiles - 1) as $i) {
                $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();
    }

    /**
     * A zip whose first entry is a directory — exercising that encryption
     * detection reads the first FILE entry.
     *
     * @param  array<string, string>  $entries
     */
    private function makeBackupZipWithLeadingDirectory(string $fileName, array $entries): void
    {
        @mkdir($this->diskRoot.self::BACKUP_DIR, 0775, true);

        $zip = new ZipArchive;
        $zip->open($this->diskRoot.self::BACKUP_DIR.'/'.$fileName, ZipArchive::CREATE);

        $zip->addEmptyDir('archive-root');

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->setPassword('test-backup-password');

        foreach (range(0, $zip->numFiles - 1) as $i) {
            if (! str_ends_with((string) $zip->getNameIndex($i), '/')) {
                $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();
    }
}

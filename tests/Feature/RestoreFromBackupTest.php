<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class RestoreFromBackupTest extends TestCase
{
    private const BACKUP_DIR = 'Exospace Backup';

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config(['backup.backup.password' => 'test-backup-password']);

        Storage::fake('local');
        Storage::fake('public');

        $this->diskRoot = Storage::disk('local')->path('');
    }

    public function test_list_reports_available_backups(): void
    {
        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $this->artisan('exospace:backup:restore', ['--list' => true, '--disk' => 'local'])
            ->assertExitCode(0)
            ->expectsOutputToContain('2026-01-15-01-30-00.zip');
    }

    public function test_list_fails_when_no_backups_exist(): void
    {
        $this->artisan('exospace:backup:restore', ['--list' => true, '--disk' => 'local'])
            ->assertExitCode(1);
    }

    public function test_plan_mode_does_not_modify_database_or_storage(): void
    {
        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $this->artisan('exospace:backup:restore', ['--disk' => 'local'])
            ->assertExitCode(0)
            ->expectsOutputToContain('PLAN ONLY');

        $this->assertSame(
            [],
            Storage::disk('public')->allFiles(),
            'plan mode must not extract any media files',
        );
    }

    public function test_plan_mode_refuses_to_restore_from_unverifiable_archive(): void
    {
        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $path = $this->diskRoot.self::BACKUP_DIR.'/2026-01-15-01-30-00.zip';
        file_put_contents($path, 'not-a-zip-at-all');

        $this->artisan('exospace:backup:restore', ['--disk' => 'local'])
            ->assertExitCode(1)
            ->expectsOutputToContain('failed verification');
    }

    public function test_execute_requires_typed_confirmation(): void
    {
        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
            '--confirm' => 'restore-me',
        ])->assertExitCode(1);

        $this->assertSame([], Storage::disk('public')->allFiles(), 'wrong confirmation token must not restore');
    }

    public function test_execute_with_wrong_interactive_answer_aborts(): void
    {
        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
        ])->expectsQuestion('Type RESTORE to continue', 'yes please')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('public')->allFiles(), 'wrong typed answer must not restore');
    }

    public function test_files_restore_overwrites_and_adds_media(): void
    {
        Storage::disk('public')->put('galleries/old/art.png', 'existing-content');

        $this->makeFilesBackup('2026-01-15-01-30-00.zip');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
            '--confirm' => 'RESTORE',
            '--only-files' => true,
        ])->assertExitCode(0);

        // New file from the archive is present.
        $this->assertSame('png-bytes-here', Storage::disk('public')->get('galleries/demo/art.png'));
        // Pre-existing file that is also in the archive is overwritten.
        $this->assertSame('png-bytes-here', Storage::disk('public')->get('galleries/old/art.png'));
        // Files NOT in the archive are never deleted by a restore.
        $this->assertSame('existing-content', Storage::disk('public')->get('galleries/old/kept.txt'));
    }

    public function test_files_restore_never_extracts_path_traversal_entries(): void
    {
        $zipPath = $this->diskRoot.self::BACKUP_DIR.'/2026-01-20-01-30-00.zip';
        @mkdir(dirname($zipPath), 0775, true);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('app/storage/app/public/galleries/ok.txt', 'safe');
        $zip->addFromString('app/storage/app/public/../../escaped.txt', 'malicious');
        $zip->addFromString('app/storage/app/private/../private-escape.txt', 'malicious');
        $zip->setPassword('test-backup-password');

        foreach (range(0, $zip->numFiles - 1) as $i) {
            $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
        }

        $zip->close();

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
            '--confirm' => 'RESTORE',
            '--only-files' => true,
        ])->assertExitCode(0);

        $this->assertSame('safe', Storage::disk('public')->get('galleries/ok.txt'));
        $this->assertFileDoesNotExist(dirname($this->diskRoot, 3).'/escaped.txt');
        $this->assertSame(['galleries/ok.txt'], Storage::disk('public')->allFiles());
    }

    public function test_db_restore_is_refused_on_non_mysql_connection(): void
    {
        $this->makeDbBackup('2026-01-15-01-00-00.zip');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--execute' => true,
            '--confirm' => 'RESTORE',
            '--only-db' => true,
        ])->assertExitCode(1)
            ->expectsOutputToContain('requires a mysql connection');
    }

    public function test_only_db_plan_skips_media_and_only_files_plan_skips_database(): void
    {
        $this->makeDbBackup('2026-01-15-01-00-00.zip');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--only-files' => true,
        ])->assertExitCode(0)
            ->expectsOutputToContain('archive contains no storage/app media entries');

        $this->artisan('exospace:backup:restore', [
            '--disk' => 'local',
            '--only-db' => true,
        ])->assertExitCode(0)
            ->expectsOutputToContain("WILL import dump entry 'db-dumps/mysql-exospace.sql'");
    }

    private function makeFilesBackup(string $fileName): void
    {
        $this->makeBackupZip($fileName, [
            'app/storage/app/public/galleries/demo/art.png' => 'png-bytes-here',
            'app/storage/app/public/galleries/old/art.png' => 'png-bytes-here',
            'app/storage/app/public/galleries/old/kept.txt' => 'existing-content',
            'app/storage/app/private/invoices/inv-1.pdf' => 'pdf-bytes-here',
        ]);
    }

    private function makeDbBackup(string $fileName): void
    {
        $this->makeBackupZip($fileName, [
            'db-dumps/mysql-exospace.sql' => "-- MySQL dump\nCREATE TABLE `users` (`id` bigint NOT NULL);\n",
        ]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makeBackupZip(string $fileName, array $entries): void
    {
        $zipPath = $this->diskRoot.self::BACKUP_DIR.'/'.$fileName;
        @mkdir(dirname($zipPath), 0775, true);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->setPassword('test-backup-password');

        foreach (range(0, $zip->numFiles - 1) as $i) {
            $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
        }

        $zip->close();
    }
}

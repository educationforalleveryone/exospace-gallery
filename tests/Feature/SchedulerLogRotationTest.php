<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerLogRotationTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('logs/scheduler.log');
        $this->deleteRotationFiles();
    }

    protected function tearDown(): void
    {
        $this->deleteRotationFiles();

        parent::tearDown();
    }

    public function test_log_below_the_threshold_is_left_in_place(): void
    {
        file_put_contents($this->logPath, 'tiny tick log');

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertFileExists($this->logPath);
        $this->assertFileDoesNotExist($this->logPath.'.1');
    }

    public function test_oversized_log_is_rotated(): void
    {
        file_put_contents($this->logPath, str_repeat('t', 10485761)); // 10MB + 1 byte

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->logPath, 'the live log is moved aside so the next scheduler tick starts fresh');
        $this->assertSame(10485761, filesize($this->logPath.'.1'));
    }

    public function test_rotation_shifts_history_and_drops_the_oldest_generation(): void
    {
        // Pre-seed the full rotation history: .1 … .5, each with a marker.
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->logPath.'.'.$i, "generation-{$i}");
        }

        file_put_contents($this->logPath, str_repeat('t', 10485761));

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertSame('generation-4', file_get_contents($this->logPath.'.5'), 'old .4 becomes .5');
        $this->assertSame('generation-3', file_get_contents($this->logPath.'.4'));
        $this->assertSame('generation-2', file_get_contents($this->logPath.'.3'));
        $this->assertSame('generation-1', file_get_contents($this->logPath.'.2'));
        $this->assertSame(10485761, filesize($this->logPath.'.1'));
        $this->assertFileDoesNotExist($this->logPath.'.6', 'history is capped at 5 rotations — the oldest generation is dropped');
    }

    private function deleteRotationFiles(): void
    {
        foreach (['', '.1', '.2', '.3', '.4', '.5', '.6'] as $suffix) {
            if (is_file($this->logPath.$suffix)) {
                @unlink($this->logPath.$suffix);
            }
        }
    }
}

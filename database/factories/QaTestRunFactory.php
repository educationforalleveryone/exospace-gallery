<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QaTestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaTestRun>
 */
class QaTestRunFactory extends Factory
{
    protected $model = QaTestRun::class;

    public function definition(): array
    {
        $total   = $this->faker->numberBetween(80, 400);
        $failed  = 0;
        $skipped = $this->faker->numberBetween(0, 4);

        return [
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'profile'     => $this->faker->randomElement(['quick_check', 'pre_release', 'security', 'billing', 'seo']),
            'environment' => 'ci',
            'safety'      => 'test-only',
            'trigger'     => 'ci',
            'runner'      => 'github-actions',
            'git_commit'  => substr($this->faker->sha256, 0, 40),
            'git_branch'  => $this->faker->randomElement(['main', 'feature/payments-2', 'seo-engine']),
            'status'      => 'passed',
            'started_at'  => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(28),
            'duration_ms' => $this->faker->numberBetween(20_000, 900_000),
            'total'       => $total,
            'passed'      => $total - $failed - $skipped,
            'failed'      => $failed,
            'errored'     => 0,
            'skipped'     => $skipped,
            'db_driver'   => 'sqlite',
            'php_version' => PHP_VERSION,
        ];
    }
}

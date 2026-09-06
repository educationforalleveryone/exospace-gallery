<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single execution (or imported artifact) of a Testing Control Center
 * profile. Aggregates live here; per-test detail in QaTestCaseResult.
 *
 * @property int $id
 * @property string $uuid
 * @property string $profile
 * @property string $environment
 * @property string $safety
 * @property string $trigger
 * @property string|null $runner
 * @property string|null $git_commit
 * @property string|null $git_branch
 * @property string|null $ci_run_url
 * @property string $status
 * @property string|null $blocked_reason
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int $total
 * @property int $passed
 * @property int $failed
 * @property int $errored
 * @property int $skipped
 * @property int $timed_out
 * @property float|null $coverage_pct
 * @property string|null $failure_class
 * @property bool $flaky_suspected
 * @property string|null $db_driver
 * @property string|null $php_version
 * @property array|null $meta
 */
class QaTestRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED       = 'queued';
    public const STATUS_RUNNING      = 'running';
    public const STATUS_PASSED       = 'passed';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_CANCELLED    = 'cancelled';
    public const STATUS_TIMED_OUT    = 'timed_out';
    public const STATUS_BLOCKED      = 'blocked';
    public const STATUS_NOT_EXECUTED = 'not_executed';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
        'meta'            => 'array',
        'flaky_suspected' => 'boolean',
        'coverage_pct'    => 'float',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(QaTestCaseResult::class, 'qa_test_run_id');
    }

    public function failures(): HasMany
    {
        return $this->cases()->whereIn('status', ['failed', 'error', 'timed_out']);
    }

    /* ------------------------------------------------------------------
    |  Presentation helpers used by CLI output and the dashboard.
    |------------------------------------------------------------------- */

    public function passedCount(): int
    {
        return $this->passed;
    }

    public function problemCount(): int
    {
        return $this->failed + $this->errored + $this->timed_out;
    }

    /** Human status incl. the honest "did not run" states. */
    public function displayStatus(): string
    {
        if (! in_array($this->status, [self::STATUS_PASSED, self::STATUS_FAILED], true)) {
            return strtoupper(str_replace('_', ' ', $this->status));
        }

        return $this->status === self::STATUS_PASSED ? 'PASSED' : 'FAILED';
    }

    public function badgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_PASSED                      => 'green',
            self::STATUS_FAILED                      => 'red',
            self::STATUS_TIMED_OUT                   => 'orange',
            self::STATUS_BLOCKED, self::STATUS_NOT_EXECUTED => 'gray',
            self::STATUS_RUNNING                     => 'blue',
            default                                  => 'slate',
        };
    }
}

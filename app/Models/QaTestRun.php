<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function passedCount(): int
    {
        return $this->passed;
    }

    public function problemCount(): int
    {
        return $this->failed + $this->errored + $this->timed_out;
    }

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

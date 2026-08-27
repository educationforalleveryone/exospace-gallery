<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-test-case outcome belonging to a QaTestRun.
 *
 * @property int $id
 * @property int $qa_test_run_id
 * @property string $test_identifier
 * @property string $classname
 * @property string $method_name
 * @property string|null $data_set
 * @property string $status
 * @property int|null $time_ms
 * @property string|null $message
 * @property string|null $detail
 * @property string|null $exception_class
 */
class QaTestCaseResult extends Model
{
    public const STATUS_PASSED    = 'passed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_ERROR     = 'error';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_WARNING   = 'warning';
    public const STATUS_TIMED_OUT = 'timed_out';

    /** Exception class prefixes indicating TEST INFRASTRUCTURE failure —
        not application logic. Conservative by design; unknown classes stay
        classified as `application` until proven otherwise. */
    public const INFRA_EXCEPTION_SIGNATURES = [
        'PDOException',
        'Illuminate\\Database\\QueryException',
        'Illuminate\\Database\\ConnectionException',
        'Illuminate\\Http\\Client\\ConnectionException',
        'Predis\\Connection\\ConnectionException',
        'RedisException',
        'Illuminate\\Contracts\\Filesystem\\FileNotFoundException',
    ];

    /** Message substrings that betray infrastructure causes */
    public const INFRA_MESSAGE_SIGNATURES = [
        'connection refused',
        'connection reset',
        'name or service not known',
        "can't connect to mysql server",
        'access denied for user',
        'unknown database',
        'connection to redis',
        'went away',
        'too many connections',
        'disk quota exceeded',
        'no space left on device',
        'permission denied',
        'failed to connect',
        'timed out after',
        'vite manifest not found',
        'unsupported cipher or incorrect key length',
        'cannot end a section without first starting one', // blade poisoned-comment / stale-compile signature
    ];

    protected $guarded = ['id'];

    protected $casts = [
        // reserved for iteration 3 enrichment
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(QaTestRun::class, 'qa_test_run_id');
    }

    /**
     * Classify this case's outcome: 'application' | 'infrastructure' |
     * 'skipped' | 'warning'; null when passed.
     */
    public function failureClass(): ?string
    {
        if (! in_array($this->status, [self::STATUS_FAILED, self::STATUS_ERROR, self::STATUS_TIMED_OUT], true)) {
            return match ($this->status) {
                self::STATUS_SKIPPED => 'skipped',
                self::STATUS_WARNING => 'warning',
                default              => null,
            };
        }

        foreach (self::INFRA_EXCEPTION_SIGNATURES as $signature) {
            if (str_starts_with((string) $this->exception_class, $signature)) {
                return 'infrastructure';
            }
        }

        $haystack = mb_strtolower(($this->message ?? '')."\n".($this->detail ?? '')."\n".($this->exception_class ?? ''));

        foreach (self::INFRA_MESSAGE_SIGNATURES as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'infrastructure';
            }
        }

        return 'application';
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Logging;

use App\Ops\Services\OpsEventIngestor;
use App\Ops\Support\ErrorClassifier;
use Illuminate\Support\Facades\App;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * OpsCenter — OpsDatabaseHandler (Monolog tap).
 *
 * Mirrors warning-and-above log records into ops_events so that
 * Log::error()/Log::critical() calls ANYWHERE in the application (and any
 * package) automatically become visible, classified control-plane events
 * — without touching a single call site.
 *
 * How it's wired (config/logging.php → channel 'ops'):
 *   'ops' => ['driver' => 'custom', 'via' => CreateOpsLogger::class, ...]
 *   Production adds it to the stack: LOG_STACK=daily,ops
 *   (see docs/MASTER_MANUAL_OPERATIONS.md — a deliberate, documented
 *   manual step so existing environments change behavior only when the
 *   operator opts in).
 *
 * Safety rules:
 *   - NEVER throws: any failure is written to stderr and dropped.
 *   - Reentrancy-guarded at the ingestor too (double protection against
 *     log→ingest→error→log loops).
 *   - Ignores records that originate from the Ops module itself when they
 *     are routine (its own errors surface via Sentry as today).
 */
class OpsDatabaseHandler extends AbstractProcessingHandler
{
    private static bool $handling = false;

    public function __construct(int|string|Level $level = Level::Warning, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$handling) {
            return; // reentrant log call from within ingestion — drop.
        }

        // Skip entirely in contexts where the app is not fully booted or the
        // ops schema hasn't been migrated yet (fresh installs, during
        // `migrate` itself, `package:discover`, etc.).
        if (! \Illuminate\Support\Facades\App::isBooted() || ! $this->schemaReady()) {
            return;
        }

        self::$handling = true;

        try {
            $ingestor = app(OpsEventIngestor::class);

            $context = $record->context;

            // Laravel reports exceptions with the Throwable under
            // context['exception'] — extract it so the classifier sees the
            // class name and we get a stack excerpt.
            $exceptionClass = null;
            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $exceptionClass = get_class($context['exception']);
                $context['exception'] = $context['exception']; // redactor handles Throwable objects
            }

            $ingestor->record([
                'source' => 'app_log',
                'severity' => ErrorClassifier::levelToSeverity($record->level->value),
                'exception_class' => $exceptionClass,
                'title' => null,
                'message' => $record->message,
                'context' => $context,
            ]);
        } catch (Throwable $e) {
            // stderr, NEVER Log:: — that would recurse.
            fwrite(STDERR, '[OpsDatabaseHandler] '.$e->getMessage().\PHP_EOL);
        } finally {
            self::$handling = false;
        }
    }

    /**
     * Has the ops schema been migrated? Guards the pre-migration window
     * (fresh installs, `migrate` itself) where writing would throw.
     */
    private function schemaReady(): bool
    {
        try {
            return cache()->remember('ops:schema-ready', 300, function () {
                return \Illuminate\Support\Facades\Schema::hasTable('ops_events');
            });
        } catch (Throwable) {
            return false;
        }
    }
}

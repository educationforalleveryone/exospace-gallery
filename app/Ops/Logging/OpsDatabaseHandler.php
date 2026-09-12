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

        if (! \Illuminate\Support\Facades\App::isBooted() || ! $this->schemaReady()) {
            return;
        }

        self::$handling = true;

        try {
            $ingestor = app(OpsEventIngestor::class);

            $context = $record->context;

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

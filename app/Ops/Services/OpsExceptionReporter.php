<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Ops\Support\LogRedactor;
use Throwable;

class OpsExceptionReporter
{
    private const EXPECTED_EXCEPTIONS = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class, // 4xx family
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ];

    public function __construct(
        private readonly OpsEventIngestor $ingestor,
        private readonly LogRedactor $redactor,
    ) {}

    public function record(Throwable $e): void
    {
        try {
            $expected = $this->isExpected($e);

            $context = $this->redactor->redactThrowable($e);

            if (app()->bound('request')) {
                $request = request();

                if ($request !== null && $request->getScheme() !== '') {
                    $context['http'] = [
                        'method' => $request->getMethod(),
                        'url' => $this->redactor->redactString($request->fullUrl()),
                        'ip' => $request->ip(),
                        'request_id' => $request->attributes->get('request_id')
                            ?: $request->header('X-Request-Id'),
                        'user_id' => $request->user()?->id,
                    ];
                }
            }

            $severity = $expected ? 'info' : 'error';

            $this->ingestor->record([
                'source' => 'exception',
                'severity' => $severity,
                'exception_class' => get_class($e),
                'title' => null, // classifier derives the headline
                'message' => $e->getMessage(),
                'context' => $context,
            ]);
        } catch (Throwable) {
            // Swallow — never interfere with the error handling path itself.
        }
    }

    private function isExpected(Throwable $e): bool
    {
        foreach (self::EXPECTED_EXCEPTIONS as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}

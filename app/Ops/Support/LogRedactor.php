<?php

declare(strict_types=1);

namespace App\Ops\Support;

class LogRedactor
{
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'auth_header', 'cookie', 'session_id', 'bearer',
        'private_key', 'signature', 'dsn', 'access_key', 'client_secret',
        'app_key', 'app-key', 'webhook_url', 'credentials', 'php_auth',
        'pwd', 'otp', '2fa', 'twofa', 'mfa', 'recovery_code', 'backup_code',
        'credit_card', 'card_number', 'cvv', 'iban',
    ];

    private const REDACTED = '[REDACTED]';

    private const MAX_DEPTH = 6;

    private const MAX_STRING = 4000;

    private const MAX_ARRAY_KEYS = 64;

    public function redactString(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // DSN-style credentials: scheme://user:password@host
        $text = preg_replace(
            '#([a-z][a-z0-9+.-]*://[^:/@\s]+):([^@/\s]{1,})@#i',
            '$1:'.self::REDACTED.'@',
            $text
        ) ?? $text;

        // Slack incoming-webhook URLs (contain the secret in the path).
        $text = preg_replace(
            '#https://hooks\.slack\.com/services/[A-Za-z0-9/_+-]+#',
            'https://hooks.slack.com/services/'.self::REDACTED,
            $text
        ) ?? $text;

        // Sentry DSNs (secret is the token segment after the last @).
        $text = preg_replace(
            '#https://[a-f0-9]{16,}@([a-z0-9.-]+\.[a-z]{2,})#i',
            'https://'.self::REDACTED.'@$1',
            $text
        ) ?? $text;

        // Bearer / Basic authorization header values.
        $text = preg_replace(
            '/(bearer|basic|token)\s+[A-Za-z0-9._~+\/-]{8,}/i',
            '$1 '.self::REDACTED,
            $text
        ) ?? $text;

        // AWS-style access key ids (AKIA/ASIA + 16 upper alnum).
        $text = preg_replace(
            '/\b(A(?:KIA|SIA)[0-9A-Z]{16})\b/',
            self::REDACTED,
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\b[a-f0-9]{24,}\b/i',
            self::REDACTED,
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\b[A-Za-z0-9+\/]{32,}={0,2}\b/',
            self::REDACTED,
            $text
        ) ?? $text;

        // PEM private key blocks.
        $text = preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
            self::REDACTED,
            $text
        ) ?? $text;

        $text = preg_replace(
            '/([A-Za-z0-9_-]*(?:password|passwd|pwd|secret|api[_-]?key|access[_-]?key|token|signature))\s*[:=>]+\s*("[^"]*"|\'[^\']*\'|[^\s,.;)]+)/i',
            '$1='.self::REDACTED,
            $text
        ) ?? $text;

        // Free-text credential leaks in quoted form: "password 'hunter2'".
        $text = preg_replace(
            '/\b(password|passwd|pwd|secret|api[_-]?key|access[_-]?key)\b\s+("[^"]*"|\'[^\']*\')/i',
            '$1 '.self::REDACTED,
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\b(password|passwd|pwd|secret)\b\s+(?!is\b|was\b|not\b|for\b|to\b|the\b|a\b|an\b|in\b|on\b|at\b|of\b|or\b|and\b|expired?\b|incorrect\b|wrong\b|required\b|missing\b|invalid\b|changed?\b|reset\b|due\b|that\b|this\b|which\b|failed\b)\S+/i',
            '$1 '.self::REDACTED,
            $text
        ) ?? $text;

        // Email addresses (PII) — keep the domain for debugging value.
        $text = preg_replace(
            '/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            '[EMAIL]@$1',
            $text
        ) ?? $text;

        // Hard length cap.
        if (mb_strlen($text) > self::MAX_STRING) {
            $text = mb_substr($text, 0, self::MAX_STRING).'…[truncated]';
        }

        return $text;
    }

    public function redactContext(mixed $context, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[MAX-DEPTH]';
        }

        if (is_string($context)) {
            return $this->redactString($context);
        }

        if (is_scalar($context) || $context === null) {
            return $context;
        }

        if ($context instanceof \Throwable) {
            return $this->redactThrowable($context);
        }

        if ($context instanceof \DateTimeInterface) {
            return $context->format(DATE_ATOM);
        }

        if (! is_array($context)) {
            return '[object:'.get_debug_type($context).']';
        }

        if (count($context) > self::MAX_ARRAY_KEYS) {
            $context = array_slice($context, 0, self::MAX_ARRAY_KEYS, true);
            $context['…'] = '[TRUNCATED]';
        }

        $result = [];
        foreach ($context as $key => $value) {
            $redactedValue = $this->isSensitiveKey((string) $key)
                ? self::REDACTED
                : $this->redactContext($value, $depth + 1);

            $result[$key] = $redactedValue;
        }

        return $result;
    }

    public function redactThrowable(\Throwable $e, int $frames = 5): array
    {
        $stack = [];
        $trace = $e->getTrace();
        foreach (array_slice($trace, 0, $frames) as $frame) {
            $stack[] = sprintf(
                '%s%s%s%s',
                isset($frame['class']) ? $frame['class'] : '',
                isset($frame['type']) ? $frame['type'] : '',
                $frame['function'] ?? '{closure}',
                isset($frame['file'])
                    ? ' @ '.$this->redactString($frame['file']).':'.($frame['line'] ?? '?')
                    : ''
            );
        }

        return [
            'class' => get_class($e),
            'message' => $this->redactString($e->getMessage()),
            'file' => $this->redactString($e->getFile()),
            'line' => $e->getLine(),
            'stack' => $stack,
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));

        $exempt = ['token_count', 'total_count', 'request_id', 'keys_only'];
        if (in_array($key, $exempt, true)) {
            return false;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Ops\Support;

/**
 * OpsCenter — LogRedactor.
 *
 * Every byte that enters ops_events passes through this class BEFORE it is
 * persisted, no matter the ingest path (log tap, exception reporter,
 * ingestion API, Coolify sync). The control plane aggregates errors from
 * many sources; without server-side redaction it would become the single
 * richest secret store on the platform.
 *
 * Three layers:
 *   1. Key-based: any array key that looks like a credential
 *      (password, token, secret, key, authorization, cookie, ...) is
 *      replaced with '[REDACTED]' regardless of value shape.
 *   2. Pattern-based: known secret SHAPES are redacted inside free text —
 *      Slack webhook URLs, Bearer tokens, DSN-style credentials
 *      (mysql://user:pass@host), Sentry DSNs, hex/base64 blobs ≥ 24 chars,
 *      email addresses (PII), private key blocks.
 *   3. Structural: stack-trace arguments are stripped (keep class::method
 *      at file:line), depth and size are capped.
 *
 * This class is deliberately dependency-free and pure so it can be unit
 * tested exhaustively (tests/Unit/OpsLogRedactorTest.php).
 */
class LogRedactor
{
    /** Context keys whose VALUES are always redacted (substring match). */
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

    /**
     * Redact a free-text message (error message, log line, payload snippet).
     */
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

        // Long hex / base64 blobs (≥24 chars) — covers API tokens, signed
        // payloads, hashes, R2/DO keys, 2Checkout secrets, APP_KEY values.
        // Short hex (like a git SHA or an id) survives: noise reduction
        // matters less than not mangling useful identifiers.
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

        // Free-text credential leaks in key-value form:
        // "password: hunter2", "secret=abc", "token => xyz", "DB_PASSWORD=...".
        // A word-char prefix is allowed so env-style names (DB_PASSWORD,
        // REDIS_PASSWORD, TWOCHECKOUT_SECRET_WORD) are caught too.
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

        // Free-text credential leaks in bare form: "password hunter2".
        // A stopword guard avoids mangling legitimate sentences like
        // "password expired" or "password is incorrect" — over-redaction
        // stays acceptable (false positives cost a little debugging
        // context; false negatives leak secrets).
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

    /**
     * Redact a structured context array (recursively).
     */
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
            // Objects (requests, models, ...): stringify shape only, never
            // contents — an object's __toString or properties can contain
            // anything.
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

            // Keys themselves can leak (e.g. token names as array keys are
            // fine; but a key like "Authorization: Bearer xyz" is not).
            $result[$key] = $redactedValue;
        }

        return $result;
    }

    /**
     * Extract a compact, argument-free stack excerpt from a Throwable.
     * Full traces live in Sentry and the daily log files — the event store
     * keeps only what an operator needs to identify the code path.
     *
     * @return array{class: string, message: string, file: string, line: int|mixed, stack: string[]}
     */
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

        // Exact-match exemptions first: keys that CONTAIN a fragment but
        // are operationally useful and carry no credential.
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

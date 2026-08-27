<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ops\Support\LogRedactor;
use PHPUnit\Framework\TestCase;

/**
 * OpsCenter — Iteration 1 — log redaction.
 *
 * The control plane aggregates errors from every source; without server-
 * side redaction it would become the richest secret store on the platform.
 * These tests attack the redactor with realistic leak vectors.
 */
class OpsLogRedactorTest extends TestCase
{
    private LogRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new LogRedactor;
    }

    public function test_redacts_password_key_in_context(): void
    {
        $out = $this->redactor->redactContext([
            'user' => 'root',
            'password' => 's3cret-pass',
            'nested' => ['DB_PASSWORD' => 'hunter2', 'note' => 'ok'],
        ]);

        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['nested']['DB_PASSWORD']);
        $this->assertSame('root', $out['user']);
        $this->assertSame('ok', $out['nested']['note']);
    }

    public function test_redacts_token_like_keys(): void
    {
        $out = $this->redactor->redactContext([
            'api_key' => 'abc',
            'authorization' => 'Bearer xyz',
            'X-API-Token' => 'tok',
            'session_id' => 'sid',
            'TWOCHECKOUT_SECRET_WORD' => 'word',
        ]);

        $this->assertSame('[REDACTED]', $out['api_key']);
        $this->assertSame('[REDACTED]', $out['authorization']);
        $this->assertSame('[REDACTED]', $out['X-API-Token']);
        $this->assertSame('[REDACTED]', $out['session_id']);
        $this->assertSame('[REDACTED]', $out['TWOCHECKOUT_SECRET_WORD']);
    }

    public function test_preserves_safe_keys(): void
    {
        $out = $this->redactor->redactContext([
            'request_id' => 'uuid-abc',
            'total_count' => 37,
            'url' => 'https://example.com/path',
            'memory_usage' => '128MB',
        ]);

        $this->assertSame('uuid-abc', $out['request_id']);
        $this->assertSame(37, $out['total_count']);
        $this->assertSame('https://example.com/path', $out['url']);
    }

    public function test_redacts_dsn_passwords_in_messages(): void
    {
        $out = $this->redactor->redactString('DB connect failed: mysql://root:hunter2@db-host:3306/exospace');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringContainsString('mysql://root:[REDACTED]@db-host', $out);
    }

    public function test_redacts_slack_webhook_urls(): void
    {
        // Synthetic webhook-shaped URL (same structure, not a real secret).
        $out = $this->redactor->redactString('POST https://hooks.slack.com/services/T0000000/B1111111/XXXXXXXXXXXXXXXXXXXXXXXX failed');

        $this->assertStringNotContainsString('XXXXXXXXXXXXXXXXXXXXXXXX', $out);
        $this->assertStringContainsString('hooks.slack.com/services/[REDACTED]', $out);
    }

    public function test_redacts_bearer_tokens(): void
    {
        // Synthetic bearer-token shape (not a real credential).
        $out = $this->redactor->redactString('curl -H "Authorization: Bearer AbCdEf12345678901234567890abcdef" failed');

        $this->assertStringNotContainsString('AbCdEf12345678901234567890abcdef', $out);
        $this->assertStringContainsString('bearer [redacted]', strtolower($out));
    }

    public function test_redacts_sentry_dsn_secret(): void
    {
        // Synthetic DSN-shaped value (32 hex chars + host), not a real DSN.
        $out = $this->redactor->redactString('DSN https://0123456789abcdef0123456789abcdef@o0000000000000000.ingest.sentry.io/0000000000000000 invalid');

        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef', $out);
    }

    public function test_redacts_long_hex_and_base64_blobs(): void
    {
        // Synthetic 50-char key-shaped blob (not a real credential).
        $out = $this->redactor->redactString('key a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2 failed');

        $this->assertStringNotContainsString('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2', $out);
    }

    public function test_keeps_short_useful_identifiers(): void
    {
        // Git SHAs and short ids must survive redaction.
        $out = $this->redactor->redactString('Deployed commit abc1234 for build #184');

        $this->assertStringContainsString('abc1234', $out);
        $this->assertStringContainsString('#184', $out);
    }

    public function test_redacts_emails_but_keeps_domain(): void
    {
        $out = $this->redactor->redactString('Login failed for jane.doe@example.com');

        $this->assertStringNotContainsString('jane.doe@example.com', $out);
        $this->assertStringContainsString('[EMAIL]@example.com', $out);
    }

    public function test_truncates_oversized_messages(): void
    {
        // Sentence-like content (no single 32+ char blob — those get fully
        // redacted by the blob rule instead of truncated).
        $out = $this->redactor->redactString(str_repeat('The quick brown fox jumps over the lazy dog. ', 200));

        $this->assertLessThanOrEqual(4020, strlen($out));
        $this->assertStringContainsString('truncated', $out);
    }

    public function test_throwable_extraction_strips_arguments(): void
    {
        $e = new \RuntimeException('Boom with secret 3f9d2c8b7a1e6f4d0c5b8a2e7f1d4c6b9a3e8f2d1c');

        $out = $this->redactor->redactThrowable($e);

        $this->assertSame(\RuntimeException::class, $out['class']);
        $this->assertArrayHasKey('stack', $out);
        $this->assertIsArray($out['stack']);
    }

    public function test_objects_are_never_dumped(): void
    {
        $object = new class
        {
            public string $secret = 'do-not-leak';
        };

        $out = $this->redactor->redactContext(['thing' => $object]);

        $this->assertStringNotContainsString('do-not-leak', var_export($out, true));
    }

    public function test_private_key_blocks_are_redacted(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA7x\n-----END RSA PRIVATE KEY-----";

        $out = $this->redactor->redactString($pem);

        $this->assertStringNotContainsString('MIIEowIBAAKCAQEA7x', $out);
    }
}

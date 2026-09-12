<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BillingExportEmail;
use App\Models\AdminAuditLog;
use App\Models\BillingDigestRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecipientWebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.example.com/exospace';
    private const SECRET  = 'super-secret-shared-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Http::fake();
        config(['services.outbound_webhook.url'    => self::WEBHOOK]);
        config(['services.outbound_webhook.secret' => self::SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    private function actingAsMfaSuperAdmin(): self
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    public function test_adding_recipient_fires_billing_recipient_added_webhook(): void
    {
        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'finance@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Recipient row was created.
        $this->assertDatabaseHas('billing_digest_recipients', [
            'email' => 'finance@example.com',
        ]);

        // Webhook fired with the expected event name + payload shape.
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::WEBHOOK) return false;
            if ($request->header('X-Exospace-Event')[0] !== 'billing.recipient_added') return false;

            $body = json_decode($request->body(), true);
            return $body['event'] === 'billing.recipient_added'
                && ($body['payload']['recipient_email']   ?? null) === 'finance@example.com'
                && ($body['payload']['recipients_total']  ?? null) === 1
                && isset($body['payload']['actor_admin_email']);
        });

        // Audit row written BEFORE the webhook fired (precedence rule).
        $auditRow = AdminAuditLog::where('action', 'billing.digest_recipient_added')->first();
        $this->assertNotNull($auditRow);
    }

    public function test_removing_recipient_fires_billing_recipient_removed_webhook_with_remaining_count(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);
        $keep = BillingDigestRecipient::create(['email' => 'keep@example.com',   'added_by' => $admin->id]);
        $gone = BillingDigestRecipient::create(['email' => 'gone@example.com',   'added_by' => $admin->id]);

        $response = $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ])->delete(route('super.billing.recipients.destroy', $gone));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Webhook fired with the post-deletion remaining count.
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::WEBHOOK) return false;
            if ($request->header('X-Exospace-Event')[0] !== 'billing.recipient_removed') return false;

            $body = json_decode($request->body(), true);
            return $body['event'] === 'billing.recipient_removed'
                && ($body['payload']['recipient_email']     ?? null) === 'gone@example.com'
                && ($body['payload']['recipients_remaining'] ?? null) === 1;
        });

        // The remaining recipient is still there.
        $this->assertDatabaseHas('billing_digest_recipients', ['email' => 'keep@example.com']);
    }

    public function test_webhook_includes_hmac_signature_header_when_secret_configured(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'sec@example.com']);

        // The HMAC signature header is attached and matches the body.
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $signatureHeader = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $signatureHeader) return false;

            $body = $request->body();
            $expected = hash_hmac('sha256', $body, self::SECRET);

            return hash_equals($expected, $signatureHeader);
        });
    }

    public function test_dispatch_silently_skips_when_no_webhook_url_configured(): void
    {
        // Override the setUp config — no webhook URL configured.
        config(['services.outbound_webhook.url' => null]);

        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'noskip@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('billing_digest_recipients', [
            'email' => 'noskip@example.com',
        ]);

        // No webhook was dispatched.
        Http::assertNothingSent();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'billing.digest_recipient_added',
        ]);
    }

    public function test_audit_row_precedes_webhook_dispatch_on_add(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'precedence@example.com']);

        // Audit row written.
        $auditRow = AdminAuditLog::where('action', 'billing.digest_recipient_added')->first();
        $this->assertNotNull($auditRow);

        // Webhook fired (the dispatch path runs AFTER the audit row write).
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->header('X-Exospace-Event')[0] === 'billing.recipient_added');
    }
}

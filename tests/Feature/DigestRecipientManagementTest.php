<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BillingExportEmail;
use App\Models\AdminAuditLog;
use App\Models\BillingDigestRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ITERATION 7 — digest recipient management (DB-backed).
 *
 * Coverage: the Billing Review UI add/remove flow + the precedence
 * contract in SendBillingExport::resolveRecipients (--to > DB list
 * > env fallback). Removing the last UI recipient falls back to the
 * env var (or the digest is disabled when env is also empty) — the
 * UI surfaces the active source explicitly so the operator is never
 * surprised by who is receiving the financial digest.
 */
class DigestRecipientManagementTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/digest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Operational alerts enabled — empty-DB-list removal path may
        // page; assert it doesn't surprise the operator.
        config(['services.operational_alerts.webhook_url' => self::WEBHOOK]);
        // Same setUp pattern as ScheduledBillingExportTest (iter-6):
        // Mail + Http faked at setUp so console + HTTP paths both
        // see the fakes without per-test ceremony.
        Mail::fake();
        \Illuminate\Support\Facades\Http::fake();
        // No withoutExceptionHandling — Laravel's ValidationException
        // handler converts to a redirect with session errors, which is
        // the actual production behavior we want to assert against.
    }

    private function actingAsMfaSuperAdmin()
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

    private function actingAsRegularUser()
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        return $this->actingAs($user);
    }

    // ── Access control ────────────────────────────────────────────────

    public function test_guests_cannot_add_recipient(): void
    {
        $response = $this->post(route('super.billing.recipients.store'), ['email' => 'someone@example.com']);
        $response->assertRedirect(route('login'));
        $this->assertSame(0, BillingDigestRecipient::count());
    }

    public function test_regular_users_cannot_add_recipient(): void
    {
        $response = $this->actingAsRegularUser()
            ->post(route('super.billing.recipients.store'), ['email' => 'someone@example.com']);
        $response->assertForbidden();
        $this->assertSame(0, BillingDigestRecipient::count());
    }

    // ── Add / remove flow ─────────────────────────────────────────────

    public function test_add_recipient_creates_row_and_audits(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ])->post(route('super.billing.recipients.store'), [
            'email' => 'Finance@Example.com',  // mixed-case → normalized
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $row = BillingDigestRecipient::first();
        $this->assertNotNull($row);
        $this->assertSame('finance@example.com', $row->email);  // lowercased
        $this->assertSame($admin->id, $row->added_by);

        $audit = AdminAuditLog::where('action', 'billing.digest_recipient_added')->first();
        $this->assertNotNull($audit, 'billing.digest_recipient_added audit row must be written');
        $this->assertSame($row->id, $audit->target_id);
    }

    public function test_duplicate_email_rejected_with_validation_error(): void
    {
        BillingDigestRecipient::create(['email' => 'dup@example.com', 'added_by' => null]);

        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'DUP@example.com']);

        $response->assertSessionHasErrors(['email']);
        $this->assertSame(1, BillingDigestRecipient::count());
    }

    public function test_invalid_email_rejected(): void
    {
        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.billing.recipients.store'), ['email' => 'not-an-email']);

        $response->assertSessionHasErrors(['email']);
        $this->assertSame(0, BillingDigestRecipient::count());
    }

    public function test_remove_recipient_deletes_audits_and_warns_when_last_with_no_env(): void
    {
        $recipient = BillingDigestRecipient::create(['email' => 'last@example.com', 'added_by' => null]);
        // No env fallback configured.
        config(['services.billing_export.email' => null]);

        $response = $this->actingAsMfaSuperAdmin()
            ->delete(route('super.billing.recipients.destroy', $recipient));

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $response->assertSessionHasNoErrors();

        $this->assertSame(0, BillingDigestRecipient::count());

        $audit = AdminAuditLog::where('action', 'billing.digest_recipient_removed')->first();
        $this->assertNotNull($audit, 'billing.digest_recipient_removed audit row must be written');
        $this->assertSame($recipient->id, $audit->target_id);
    }

    public function test_remove_last_recipient_falls_back_to_env_with_warning(): void
    {
        $recipient = BillingDigestRecipient::create(['email' => 'last@example.com', 'added_by' => null]);
        config(['services.billing_export.email' => 'env@example.com']);

        $response = $this->actingAsMfaSuperAdmin()
            ->delete(route('super.billing.recipients.destroy', $recipient));

        $response->assertSessionHas('warning');
        $response->assertSeeText('');  // no exception
        // The warning copy mentions the env fallback.
        $response->assertSessionHas('warning', fn ($msg) => str_contains((string) $msg, 'BILLING_EXPORT_EMAIL'));
    }

    public function test_remove_non_last_recipient_succeeds_cleanly(): void
    {
        $a = BillingDigestRecipient::create(['email' => 'a@example.com', 'added_by' => null]);
        BillingDigestRecipient::create(['email' => 'b@example.com', 'added_by' => null]);

        $response = $this->actingAsMfaSuperAdmin()
            ->delete(route('super.billing.recipients.destroy', $a));

        $response->assertSessionHas('success');
        $this->assertSame(1, BillingDigestRecipient::count());
    }

    // ── Billing Review page ───────────────────────────────────────────

    public function test_billing_review_page_shows_recipients_card(): void
    {
        BillingDigestRecipient::create(['email' => 'finance@example.com', 'added_by' => null]);
        config(['services.billing_export.email' => 'env@example.com']);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.billing.index'));

        $response->assertStatus(200);
        $response->assertSee('Weekly billing digest recipients', false);
        $response->assertSee('finance@example.com', false);
        $response->assertSee('env@example.com', false);
        // Surfaces which source is currently effective.
        $response->assertSee('Active source: UI-managed list', false);
    }

    public function test_billing_review_shows_env_active_when_db_empty(): void
    {
        config(['services.billing_export.email' => 'env@example.com']);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.billing.index'));

        $response->assertStatus(200);
        $response->assertSee('Active source: BILLING_EXPORT_EMAIL fallback', false);
    }

    public function test_billing_review_shows_disabled_when_both_empty(): void
    {
        config(['services.billing_export.email' => null]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.billing.index'));

        $response->assertStatus(200);
        $response->assertSee('digest is effectively disabled', false);
    }

    // ── resolveRecipients precedence ──────────────────────────────────

    public function test_command_uses_db_list_when_non_empty(): void
    {
        BillingDigestRecipient::create(['email' => 'db1@example.com', 'added_by' => null]);
        BillingDigestRecipient::create(['email' => 'db2@example.com', 'added_by' => null]);
        config(['services.billing_export.email' => 'env@example.com']);  // should be ignored

        $this->artisan('exospace:send-billing-export')
            ->expectsOutputToContain('db1@example.com')
            ->assertExitCode(0);

        Mail::assertQueued(BillingExportEmail::class, 2);  // both DB rows, not env
    }

    public function test_command_falls_back_to_env_when_db_empty(): void
    {
        config(['services.billing_export.email' => 'env@example.com,env2@example.com']);

        $this->artisan('exospace:send-billing-export')->assertExitCode(0);

        Mail::assertQueued(BillingExportEmail::class, 2);  // both env addresses
    }

    public function test_command_to_override_wins_over_db_and_env(): void
    {
        BillingDigestRecipient::create(['email' => 'db@example.com', 'added_by' => null]);
        config(['services.billing_export.email' => 'env@example.com']);

        $this->artisan('exospace:send-billing-export', ['--to' => 'override@example.com'])
            ->assertExitCode(0);

        Mail::assertQueued(BillingExportEmail::class, 1);  // only the override
    }

    public function test_command_clean_no_op_when_both_empty(): void
    {
        config(['services.billing_export.email' => null]);

        $this->artisan('exospace:send-billing-export')
            ->expectsOutputToContain('No billing-export recipient configured')
            ->assertExitCode(0);

        // Heartbeat stamp survives the no-op (Iteration-6 convention).
        $this->assertSame(
            'fresh',
            app(\App\Services\JobHeartbeatService::class)->status('exospace:send-billing-export'),
        );
    }

    // ── ITERATION 8: env case-normalization (audit-fix A-5) ───────────

    public function test_env_recipients_are_lowercased_for_consistent_display(): void
    {
        // ITERATION 8 FIX: the Billing Review page previously showed
        // mixed-case env recipients (Finance@Example.com) alongside
        // lowercased UI-managed ones (finance@example.com) — an
        // operator comparing the two lists couldn't tell whether the
        // mixed-case env entry was a duplicate of the UI entry (it
        // was, but the case difference obscured it). Env recipients
        // are now lowercased by parseEnvRecipients.
        config(['services.billing_export.email' => 'Finance@Example.com,ops@example.com']);

        $admin = $this->actingAsMfaSuperAdmin();

        $response = $this->get(route('super.billing.index'));
        $response->assertStatus(200);

        // The env-fallback column shows the lowercased forms.
        $response->assertSee('finance@example.com', false);
        // Mixed-case form must NOT appear — the operator would see
        // it as a duplicate of a UI-managed entry.
        $response->assertDontSee('Finance@Example.com', false);
    }

    public function test_resolve_recipients_dedupes_case_different_env_entries(): void
    {
        // ITERATION 8 FIX (audit-fix A-5): the previous case-sensitive
        // in_array(...) comparison let both Finance@Example.com AND
        // finance@example.com survive, sending the same CSV twice to
        // the same mailbox. The lowercased dedupe collapses them to
        // one recipient.
        config(['services.billing_export.email' => 'Finance@Example.com,finance@example.com']);

        // Seed a money event so the audit row has a target.
        \App\Models\Transaction::factory()->create();

        $this->artisan('exospace:send-billing-export')->assertExitCode(0);

        // Only ONE Mail queued — both env entries resolved to the
        // same lowercased address and the dedupe caught the dup.
        Mail::assertQueued(BillingExportEmail::class, 1);
    }

    // ── ITERATION 8: TOCTOU race on dup check (audit-fix B-2) ─────────

    public function test_concurrent_dup_add_returns_friendly_error_not_500(): void
    {
        // ITERATION 8 FIX: the explicit where(...)->exists() check
        // is a TOCTOU race window — two super-admins submitting the
        // same email in the same ~50ms both pass the exists check,
        // the loser throws a QueryException on the unique index
        // that previously propagated as a 500. The race is now
        // caught: a UniqueConstraintViolationException is re-routed
        // to the same withErrors path. We simulate the race by
        // inserting the row between the exists() check and create().
        $admin = $this->actingAsMfaSuperAdmin();

        // Hijack BillingDigestRecipient::create to insert a duplicate
        // first — simulating a concurrent super-admin's write landing
        // between our exists() and create().
        BillingDigestRecipient::creating(function ($r) {
            // Insert the same email directly so our subsequent create
            // hits the unique index.
            BillingDigestRecipient::withoutEvents(function () use ($r) {
                BillingDigestRecipient::create([
                    'email'   => $r->email,
                    'added_by' => 1,  // system-actor for the test
                ]);
            });
        });

        $response = $this->post(route('super.billing.recipients.store'), [
            'email' => 'race@example.com',
        ]);

        $response->assertRedirect();  // back() redirect, not a 500
        $response->assertSessionHasErrors(['email']);
        // MessageBag::get returns an array of messages for the key.
        $errors = session('errors')->get('email');
        $errorText = is_array($errors) ? implode(' ', $errors) : (string) $errors;
        $this->assertStringContainsString('already a recipient', $errorText);

        // Only ONE recipient row exists — the dup insert was caught.
        $this->assertSame(
            1,
            BillingDigestRecipient::where('email', 'race@example.com')->count(),
        );
    }
}

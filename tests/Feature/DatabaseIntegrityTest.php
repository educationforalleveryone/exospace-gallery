<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AnonymizeTransactionPii;
use App\Console\Commands\PruneTransactionsByPartition;
use App\Models\AnalyticsEvent;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_c3_analytics_event_fillable_does_not_include_country(): void
    {
        $this->assertNotContains('country', (new AnalyticsEvent())->getFillable(),
            'C-3: AnalyticsEvent::$fillable must not include "country" (the column was dropped).');
    }

    public function test_c3_analytics_event_can_be_created_without_country(): void
    {
        $gallery = \App\Models\Gallery::factory()->create();
        $event = AnalyticsEvent::create([
            'gallery_id' => $gallery->id,
            'event' => 'view',
            'session_token' => 'test-session',
            'created_at' => now(),
        ]);

        $this->assertNotNull($event->id);
        $this->assertFalse(Schema::hasColumn('analytics_events', 'country'),
            'C-3: analytics_events table should not have a country column.');
    }

    public function test_g2_user_deletion_anonymizes_transactions(): void
    {
        $user = User::factory()->create([
            'email' => 'gdpr-test@example.com',
            'name' => 'GDPR Test User',
        ]);

        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'customer_email' => 'gdpr-test@example.com',
            'customer_name' => 'GDPR Test User',
            'amount' => 29.00,
            'currency' => 'USD',
            'plan' => 'pro',
            'status' => 'completed',
        ]);

        // Delete the user via the service
        app(UserDeletionService::class)->deleteUser($user, 'G-2 test');

        // The transaction should still exist (not deleted)
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
        ]);

        // The PII should be anonymized
        $transaction->refresh();
        $this->assertStringStartsWith('anonymized:', $transaction->customer_email,
            'G-2: customer_email should be anonymized (start with "anonymized:") after user deletion.');
        $this->assertNotEquals('gdpr-test@example.com', $transaction->customer_email,
            'G-2: customer_email should NOT be the original email after anonymization.');
        $this->assertNull($transaction->customer_name,
            'G-2: customer_name should be null after anonymization.');

        // The financial record should be preserved
        $this->assertEquals('29.00', $transaction->amount, 'G-2: financial amount should be preserved.');
        $this->assertEquals('pro', $transaction->plan, 'G-2: plan should be preserved.');
        $this->assertEquals('completed', $transaction->status, 'G-2: status should be preserved.');
    }

    public function test_g5_user_deletion_anonymizes_invoices(): void
    {
        $user = User::factory()->create([
            'email' => 'invoice-gdpr@example.com',
            'name' => 'Invoice GDPR User',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'customer_email' => 'invoice-gdpr@example.com',
            'customer_name' => 'Invoice GDPR User',
            'billing_address' => '123 Test St, Test City, TC 12345',
            'amount' => 99.00,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'currency' => 'USD',
            'plan' => 'studio',
            'invoice_number' => 'INV-2026-00001',
            'issued_at' => now(),
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'G-5 test');

        // The invoice should still exist (G-1 fix: nullOnDelete, not cascade)
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
        ]);

        // The PII should be anonymized
        $invoice->refresh();
        $this->assertStringStartsWith('anonymized:', $invoice->customer_email,
            'G-5: customer_email should be anonymized after user deletion.');
        $this->assertNull($invoice->customer_name,
            'G-5: customer_name should be null after anonymization.');
        $this->assertNull($invoice->billing_address,
            'G-5: billing_address should be null after anonymization.');

        // The financial record should be preserved
        $this->assertEquals('99.00', $invoice->amount, 'G-5: amount should be preserved.');
        $this->assertEquals('studio', $invoice->plan, 'G-5: plan should be preserved.');
        $this->assertEquals('INV-2026-00001', $invoice->invoice_number, 'G-5: invoice_number should be preserved.');
    }

    public function test_g5_anonymize_pii_command_covers_invoices(): void
    {
        $oldDate = now()->subMonths(20); // older than 18-month retention

        $invoice = Invoice::factory()->create([
            'customer_email' => 'old-invoice@example.com',
            'customer_name' => 'Old Invoice User',
            'billing_address' => '456 Old St',
            'issued_at' => $oldDate,
        ]);

        // Run the command
        $this->artisan('exospace:anonymize-pii', ['--retention-months' => 18])
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertStringStartsWith('anonymized:', $invoice->customer_email,
            'G-5: old invoice customer_email should be anonymized by the command.');
        $this->assertNull($invoice->customer_name,
            'G-5: old invoice customer_name should be null after anonymization.');
        $this->assertNull($invoice->billing_address,
            'G-5: old invoice billing_address should be null after anonymization.');
    }

    public function test_g5_anonymize_pii_command_preserves_recent_invoices(): void
    {
        $recentDate = now()->subMonths(6); // within 18-month retention

        $invoice = Invoice::factory()->create([
            'customer_email' => 'recent-invoice@example.com',
            'customer_name' => 'Recent Invoice User',
            'billing_address' => '789 Recent St',
            'issued_at' => $recentDate,
        ]);

        $this->artisan('exospace:anonymize-pii', ['--retention-months' => 18])
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertEquals('recent-invoice@example.com', $invoice->customer_email,
            'G-5: recent invoice customer_email should NOT be anonymized.');
        $this->assertEquals('Recent Invoice User', $invoice->customer_name,
            'G-5: recent invoice customer_name should NOT be anonymized.');
    }

    public function test_g5_anonymize_pii_command_is_idempotent(): void
    {
        $oldDate = now()->subMonths(20);

        $invoice = Invoice::factory()->create([
            'customer_email' => 'idempotent@example.com',
            'customer_name' => 'Idempotent User',
            'issued_at' => $oldDate,
        ]);

        // Run twice
        $this->artisan('exospace:anonymize-pii', ['--retention-months' => 18])->assertSuccessful();
        $firstRunEmail = Invoice::find($invoice->id)->customer_email;

        $this->artisan('exospace:anonymize-pii', ['--retention-months' => 18])->assertSuccessful();
        $secondRunEmail = Invoice::find($invoice->id)->customer_email;

        $this->assertEquals($firstRunEmail, $secondRunEmail,
            'G-5: Anonymization should be idempotent (running twice produces the same hash).');
    }

    public function test_g5_anonymize_pii_command_dry_run_does_not_modify(): void
    {
        // G-5 FIX: --dry-run should not modify any rows.
        $oldDate = now()->subMonths(20);

        $invoice = Invoice::factory()->create([
            'customer_email' => 'dryrun@example.com',
            'customer_name' => 'Dry Run User',
            'issued_at' => $oldDate,
        ]);

        $this->artisan('exospace:anonymize-pii', ['--retention-months' => 18, '--dry-run' => true])
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertEquals('dryrun@example.com', $invoice->customer_email,
            'G-5: --dry-run should not modify the invoice.');
    }

    public function test_g1_invoices_user_id_fk_is_set_null_not_cascade(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        app(UserDeletionService::class)->deleteUser($user, 'G-1 FK test');

        $invoice->refresh();
        $this->assertNull($invoice->user_id,
            'G-1: invoice.user_id should be null after user deletion (nullOnDelete FK, not cascade).');
        $this->assertNotNull($invoice->id,
            'G-1: invoice row should still exist (not cascade-deleted).');
    }

    public function test_c1_prune_command_uses_unix_timestamp_not_from_days(): void
    {
        $commandFile = file_get_contents(
            app_path('Console/Commands/PruneTransactionsByPartition.php')
        );

        $commandCode = trim(preg_replace([
            '~/\*.*?\*/~s',
            '~^\s*//.*$~m',
        ], '', $commandFile));

        $this->assertStringContainsString('Carbon::createFromTimestamp', $commandCode,
            'C-1: PruneTransactionsByPartition should use Carbon::createFromTimestamp (Unix timestamp), not FROM_DAYS.');

        $this->assertStringNotContainsString('FROM_DAYS', $commandCode,
            'C-1: PruneTransactionsByPartition should NOT use FROM_DAYS in executable code (it expects a day number, not a Unix timestamp).');
    }

    public function test_g3_consolidated_users_migration_has_all_columns(): void
    {
        $migrationFile = file_get_contents(
            database_path('migrations/2026_07_02_160000_create_users_table_consolidated.php')
        );

        $requiredColumns = [
            'subscription_id',
            'subscription_status',
            'subscription_cancelled_at',
            'subscription_ends_at',
            'dunning_step',
            'dunning_last_sent_at',
            'trial_ends_at',
            'google2fa_secret',
            'mfa_enabled_at',
            'mfa_backup_codes',
            'marketing_consent',
            'google_id',
            'github_id',
            'avatar_url',
            'lifecycle_nudged_at',
            'inactive_nudged_at',
            'plan_expiry_reminded_at',
            'has_password',
            'password_set_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertStringContainsString($column, $migrationFile,
                "G-3: Consolidated users migration must include column '{$column}'.");
        }
    }

    public function test_g4_consolidated_galleries_migration_has_soft_deletes(): void
    {
        $migrationFile = file_get_contents(
            database_path('migrations/2026_07_02_150000_create_galleries_table_consolidated.php')
        );

        $this->assertStringContainsString('softDeletes', $migrationFile,
            'G-4: Consolidated galleries migration must include $table->softDeletes().');
    }
}

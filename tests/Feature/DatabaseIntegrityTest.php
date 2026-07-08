<?php

declare(strict_types=1);

/**
 * Iteration-003 regression tests for database integrity fixes.
 *
 * Covers:
 *   - C-1: PruneTransactionsByPartition uses FROM_UNIXTIME (not FROM_DAYS)
 *   - C-3: AnalyticsEvent does not have 'country' in $fillable
 *   - G-1: invoices.user_id FK is nullOnDelete (not cascade)
 *   - G-2: UserDeletionService anonymizes transactions on user delete
 *   - G-5: UserDeletionService anonymizes invoices on user delete
 *        + AnonymizeTransactionPii covers invoices
 *
 * Run: php artisan test --filter=DatabaseIntegrityTest
 */

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
        // C-3 FIX: 'country' was removed from $fillable because the column
        // was dropped by 2026_07_04_000004_drop_country_from_analytics_events.php.
        $this->assertNotContains('country', (new AnalyticsEvent())->getFillable(),
            'C-3: AnalyticsEvent::$fillable must not include "country" (the column was dropped).');
    }

    public function test_c3_analytics_event_can_be_created_without_country(): void
    {
        // C-3 FIX: creating an AnalyticsEvent should not try to write a
        // 'country' column (which doesn't exist).
        $event = AnalyticsEvent::create([
            'gallery_id' => 1,
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
        // G-2 FIX: When a user is deleted, their transactions should be
        // ANONYMIZED (not deleted), preserving the financial record for
        // tax audit compliance.
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
        // G-5 FIX: When a user is deleted, their invoices should be
        // ANONYMIZED (not deleted), preserving the financial record.
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
        // G-5 FIX: The exospace:anonymize-pii command should anonymize
        // invoices older than the retention window, not just transactions.
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
        // G-5 FIX: Recent invoices (within the retention window) should NOT
        // be anonymized.
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
        // G-5 FIX: Running the command twice should be a no-op on already-
        // anonymized rows.
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
        // G-1 FIX: When a user is deleted, the invoice row should survive
        // with user_id = null (not be cascade-deleted).
        // This is verified by test_g5_user_deletion_anonymizes_invoices above
        // (the invoice still exists after user deletion). This test explicitly
        // checks the user_id is null.
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
        // C-1 FIX: The PruneTransactionsByPartition command should interpret
        // PARTITION_DESCRIPTION as a Unix timestamp (not a day number for
        // FROM_DAYS). This test verifies the command source code contains
        // the correct conversion.
        //
        // We can't test the actual partition-pruning behavior without a
        // MySQL partitioned table (SQLite doesn't support partitioning),
        // but we can verify the command uses the correct API.
        $commandFile = file_get_contents(
            app_path('Console/Commands/PruneTransactionsByPartition.php')
        );

        $this->assertStringContainsString('Carbon::createFromTimestamp', $commandFile,
            'C-1: PruneTransactionsByPartition should use Carbon::createFromTimestamp (Unix timestamp), not FROM_DAYS.');

        $this->assertStringNotContainsString('FROM_DAYS', $commandFile,
            'C-1: PruneTransactionsByPartition should NOT use FROM_DAYS (it expects a day number, not a Unix timestamp).');
    }

    public function test_g3_consolidated_users_migration_has_all_columns(): void
    {
        // G-3 FIX: The consolidated users migration should include all
        // columns from the additive migrations. This test verifies the
        // migration source code contains the missing columns.
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
        // G-4 FIX: The consolidated galleries migration should include
        // $table->softDeletes().
        $migrationFile = file_get_contents(
            database_path('migrations/2026_07_02_150000_create_galleries_table_consolidated.php')
        );

        $this->assertStringContainsString('softDeletes', $migrationFile,
            'G-4: Consolidated galleries migration must include $table->softDeletes().');
    }
}

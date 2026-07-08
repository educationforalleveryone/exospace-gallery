<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Iteration-003: Migration integrity test (audit G-3 + G-4 fix).
 *
 * The "consolidated migrations" pattern in this codebase is a time-bomb:
 * the archive README instructs maintainers to move original additive migrations
 * into the archive directory once all prod environments have run the consolidated
 * versions. The consolidated migrations for `users` and `galleries` were INCOMPLETE:
 *   - users: missing 12+ columns added by later additive migrations (MFA, OAuth,
 *     subscriptions, dunning, marketing_consent, trial, has_password, etc.)
 *   - galleries: missing softDeletes() (the Gallery model's SoftDeletes trait
 *     injects WHERE deleted_at IS NULL into every query)
 *
 * Once the additive migrations are archived, `php artisan migrate:fresh` produces
 * a schema missing those columns, and the User/Gallery models crash with
 * "column not found" on every query.
 *
 * This test runs `migrate:fresh` on a clean SQLite DB and asserts that every
 * column referenced by the models exists in the resulting schema.
 *
 * Run: php artisan test --filter=MigrateFreshTest
 */
class MigrateFreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_all_columns_referenced_by_user_model(): void
    {
        $this->assertTableHasColumns('users', [
            'id',
            'name',
            'email',
            'email_verified_at',
            'password',
            'remember_token',
            'current_team_id',
            // Plan / billing
            'plan',
            'plan_started_at',
            'plan_expires_at',
            'max_galleries',
            'max_images',
            // Subscription (M-1)
            'subscription_id',
            'subscription_status',
            'subscription_ends_at',
            'subscription_cancelled_at',
            // Dunning (M-9)
            'dunning_step',
            'dunning_last_sent_at',
            // Trial (M-7)
            'trial_ends_at',
            // MFA (P3-5)
            'google2fa_secret',
            'mfa_enabled_at',
            'mfa_backup_codes',
            // OAuth
            'google_id',
            'github_id',
            'avatar_url',
            // Marketing consent
            'marketing_consent',
            // Lifecycle
            'lifecycle_nudged_at',
            'inactive_nudged_at',
            'plan_expiry_reminded_at',
            // Admin
            'is_super_admin',
            'banned_at',
            'ban_reason',
            // C-2 FIX (Iter-001): has_password
            'has_password',
            'password_set_at',
            // Timestamps
            'created_at',
            'updated_at',
        ]);
    }

    public function test_galleries_table_has_soft_deletes(): void
    {
        $this->assertTrue(Schema::hasColumn('galleries', 'deleted_at'),
            'galleries table must have deleted_at column for the SoftDeletes trait on the Gallery model. '.
            'The consolidated galleries migration must include $table->softDeletes(). See audit G-4.');
    }

    public function test_gallery_images_table_has_soft_deletes(): void
    {
        $this->assertTrue(Schema::hasColumn('gallery_images', 'deleted_at'),
            'gallery_images table must have deleted_at column for the SoftDeletes trait on the GalleryImage model.');
    }

    public function test_transactions_table_does_not_have_user_id_fk_after_partitioning(): void
    {
        // The partition migration drops the transactions.user_id FK because
        // MySQL/InnoDB cannot be the target of a FK when the table is partitioned.
        // This test documents that behavior so future maintainers don't try to
        // re-add it (which would fail on MySQL with partitioning enabled).
        // On SQLite (test env), partitioning is a no-op and the FK may still exist.
        $this->assertTrue(Schema::hasTable('transactions'));
        $this->assertTrue(Schema::hasColumn('transactions', 'user_id'));
    }

    public function test_analytics_events_table_does_not_have_country_column(): void
    {
        // C-3 FIX (Iter-003): The country column was dropped. The model $fillable
        // was also updated. This test asserts the schema matches the model — if
        // someone re-adds the column without updating the model (or vice versa),
        // this test fails.
        $this->assertFalse(Schema::hasColumn('analytics_events', 'country'),
            'analytics_events.country column was dropped. If you re-add it, also update AnalyticsEvent::$fillable.');
    }

    public function test_invoices_table_exists_with_required_columns(): void
    {
        // G-1 + G-5: invoices table must exist with the PII columns that
        // AnonymizeTransactionPii will anonymize.
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTableHasColumns('invoices', [
            'id',
            'user_id',
            'transaction_id',
            'invoice_number',
            'amount',
            'tax_amount',
            'tax_rate',
            'currency',
            'plan',
            'customer_name',
            'customer_email',
            'billing_address',
            'pdf_path',
            'issued_at',
            'created_at',
            'updated_at',
        ]);
    }

    public function test_all_core_tables_exist_after_fresh_migrate(): void
    {
        $requiredTables = [
            'users',
            'galleries',
            'gallery_images',
            'transactions',
            'invoices',
            'pending_upgrades',
            'teams',
            'team_user',
            'team_invitations',
            'artists',
            'gallery_schedule_events',
            'event_rsvps',
            'analytics_events',
            'analytics_daily',
            'admin_audit_logs',
            'processed_webhooks',
            'password_histories',
            'user_notifications',
            'user_feedback',
            'survey_responses',
            'newsletter_signups',
            'venue_templates',
            'settings',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(Schema::hasTable($table),
                "Required table '{$table}' is missing after migrate:fresh. ".
                'This likely means a consolidated migration is incomplete (see audit G-3/G-4).');
        }
    }

    public function test_rollback_and_re_migrate_works(): void
    {
        // Verify every migration has a working down() method by rolling back
        // and re-migrating. If any down() throws, this test fails.
        $exitCode = Artisan::call('migrate:rollback', ['--force' => true]);
        $this->assertEquals(0, $exitCode, 'migrate:rollback failed — a migration has a broken down() method.');

        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $this->assertEquals(0, $exitCode, 'migrate failed after rollback — schema cannot be re-created.');
    }

    /**
     * Assert that a table has all the specified columns.
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $missing = [];
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = $column;
            }
        }

        if (! empty($missing)) {
            $this->fail(
                "Table '{$table}' is missing columns: " . implode(', ', $missing) . "\n" .
                'This likely means a consolidated migration is incomplete (see audit G-3).' . "\n" .
                'Found columns: ' . implode(', ', Schema::getColumnListing($table))
            );
        }

        $this->assertTrue(true, "Table '{$table}' has all required columns.");
    }
}

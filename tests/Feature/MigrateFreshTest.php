<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

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
            // Subscription
            'subscription_id',
            'subscription_status',
            'subscription_ends_at',
            'subscription_cancelled_at',
            // Dunning
            'dunning_step',
            'dunning_last_sent_at',
            // Trial
            'trial_ends_at',
            // MFA
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
                        'inactive_nudged_at',
            'plan_expiry_reminded_at',
            // Admin
            'is_super_admin',
            'banned_at',
            'ban_reason',
            // has_password
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
            'The consolidated galleries migration must include $table->softDeletes().');
    }

    public function test_gallery_images_table_has_soft_deletes(): void
    {
        $this->assertTrue(Schema::hasColumn('gallery_images', 'deleted_at'),
            'gallery_images table must have deleted_at column for the SoftDeletes trait on the GalleryImage model.');
    }

    public function test_transactions_table_does_not_have_user_id_fk_after_partitioning(): void
    {
        $this->assertTrue(Schema::hasTable('transactions'));
        $this->assertTrue(Schema::hasColumn('transactions', 'user_id'));
    }

    public function test_analytics_events_table_does_not_have_country_column(): void
    {
        $this->assertFalse(Schema::hasColumn('analytics_events', 'country'),
            'analytics_events.country column was dropped. If you re-add it, also update AnalyticsEvent::$fillable.');
    }

    public function test_invoices_table_exists_with_required_columns(): void
    {
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
                'This likely means a consolidated migration is incomplete.');
        }
    }

    public function test_rollback_and_re_migrate_works(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $exitCode = Artisan::call('migrate:rollback', ['--force' => true]);
            $this->assertEquals(0, $exitCode, 'migrate:rollback failed — a migration has a broken down() method.');

            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $this->assertEquals(0, $exitCode, 'migrate failed after rollback — schema cannot be re-created.');
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

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
                'This likely means a consolidated migration is incomplete.' . "\n" .
                'Found columns: ' . implode(', ', Schema::getColumnListing($table))
            );
        }

        $this->assertTrue(true, "Table '{$table}' has all required columns.");
    }
}

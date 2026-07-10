<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Iteration-010 regression tests for audit GDPR PII findings:
 *   - G-2: Transactions anonymized on user deletion (was fixed in Iter-003, locked here)
 *   - G-5: Invoices anonymized on user deletion (was fixed in Iter-003, locked here)
 *   - G-6: AdminAuditLog payload PII filtered at write time + scheduled scrub
 *   - G-7: Artist email + name nulled when matches deleted user (was partial in Iter-003,
 *     completed in Iter-010 with name → "Anonymous Artist")
 */
class GdprPiiAnonymizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function g2_transactions_are_anonymized_on_user_deletion(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'name'  => 'Victim User',
        ]);

        Transaction::factory()->count(3)->create([
            'user_id'        => $user->id,
            'customer_email' => 'victim@example.com',
            'customer_name'  => 'Victim User',
            'amount'         => 99.00,
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'Self-serve deletion');

        $transactions = DB::table('transactions')->where('user_id', $user->id)->get();

        $this->assertCount(3, $transactions, 'Transactions should still exist (financial record retention)');

        foreach ($transactions as $t) {
            $this->assertStringStartsWith('anonymized:', $t->customer_email, 'Email should be hashed');
            $this->assertStringNotContainsString('victim@example.com', $t->customer_email);
            $this->assertNull($t->customer_name, 'Name should be null');
            $this->assertSame('99.00', $t->amount, 'Financial amount preserved');
        }
    }

    /** @test */
    public function g5_invoices_are_anonymized_on_user_deletion(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@example.com',
            'name'  => 'Victim User',
        ]);

        $transaction = Transaction::factory()->create([
            'user_id'        => $user->id,
            'customer_email' => 'victim@example.com',
        ]);

        Invoice::create([
            'user_id'         => $user->id,
            'transaction_id'  => $transaction->id,
            'invoice_number'  => 'INV-2026-TEST1',
            'amount'          => 99.00,
            'tax_amount'      => 0,
            'tax_rate'        => 0,
            'currency'        => 'USD',
            'plan'            => 'pro',
            'customer_name'   => 'Victim User',
            'customer_email'  => 'victim@example.com',
            'billing_address' => "123 Main St\nSan Francisco, CA",
            'issued_at'       => now(),
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'Self-serve deletion');

        $invoice = DB::table('invoices')->where('invoice_number', 'INV-2026-TEST1')->first();

        $this->assertNotNull($invoice, 'Invoice row should still exist (preserved for tax audit)');
        $this->assertNull($invoice->user_id, 'user_id should be null (nullOnDelete FK)');
        $this->assertStringStartsWith('anonymized:', $invoice->customer_email);
        $this->assertStringNotContainsString('victim@example.com', $invoice->customer_email);
        $this->assertNull($invoice->customer_name);
        $this->assertNull($invoice->billing_address);
        $this->assertSame('99.00', $invoice->amount, 'Financial amount preserved');
    }

    /** @test */
    public function g7_artist_email_and_name_are_anonymized_when_email_matches_deleted_user(): void
    {
        $user = User::factory()->create([
            'email' => 'curator@example.com',
            'name'  => 'Jane Curator',
        ]);

        // Artist whose email matches the deleted user — should be fully anonymized.
        $matchingArtist = Artist::create([
            'name'       => 'Jane Curator',
            'slug'       => 'jane-curator',
            'email'      => 'curator@example.com',
            'created_by' => $user->id,
        ]);

        // Artist with a DIFFERENT email — should be left alone (not the deleted user's PII).
        $otherArtist = Artist::create([
            'name'       => 'Other Artist',
            'slug'       => 'other-artist-' . uniqid(),
            'email'      => 'someone-else@example.com',
            'created_by' => $user->id,
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'Self-serve deletion');

        $matching = $matchingArtist->fresh();
        $other    = $otherArtist->fresh();

        // G-7 (Iter-010 complete): email + name both anonymized.
        $this->assertNull($matching->email, 'Matching artist email must be null');
        $this->assertSame('Anonymous Artist', $matching->name, 'Matching artist name must be "Anonymous Artist"');

        // Other artist unchanged.
        $this->assertSame('someone-else@example.com', $other->email);
        $this->assertSame('Other Artist', $other->name);
    }

    /** @test */
    public function g7_artist_anonymization_also_catches_artists_with_null_portrait_path(): void
    {
        // Iter-010 fix: the Iter-003 fix only ran on artists with non-null
        // portrait_path. Iter-010 added a second pass for null-portrait artists.
        $user = User::factory()->create([
            'email' => 'curator@example.com',
        ]);

        $artist = Artist::create([
            'name'          => 'Curator Artist',
            'slug'          => 'curator-artist-' . uniqid(),
            'email'         => 'curator@example.com',
            'portrait_path' => null, // already null — Iter-003 fix would have skipped this
            'created_by'    => $user->id,
        ]);

        app(UserDeletionService::class)->deleteUser($user, 'Self-serve deletion');

        $fresh = $artist->fresh();
        $this->assertNull($fresh->email, 'Iter-010 must catch null-portrait artists too');
        $this->assertSame('Anonymous Artist', $fresh->name);
    }

    /** @test */
    public function g6_admin_audit_log_scrubs_pii_at_write_time(): void
    {
        $superAdmin = User::factory()->create([
            'email'          => 'admin@example.com',
            'is_super_admin' => true,
        ]);

        $target = User::factory()->create([
            'email' => 'victim@example.com',
            'name'  => 'Victim Name',
        ]);

        $this->actingAs($superAdmin);

        // Mark the target as dirty to trigger the _changed auto-capture.
        $target->ban_reason = 'Spamming';
        $target->isBanned = true;

        AdminAuditLog::record('user_banned', $target, [
            'email'         => 'victim@example.com',  // explicit PII in payload
            'plan'          => 'free',                // non-PII context — preserved
            'from'          => 'free',
            'to'            => 'banned',
            'customer_name' => 'Victim Name',         // explicit PII in payload
        ]);

        $log = AdminAuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $payload = $log->payload;

        // Explicit-payload PII keys must be hashed.
        $this->assertStringStartsWith('pii:', $payload['email']);
        $this->assertStringNotContainsString('victim@example.com', $payload['email']);
        $this->assertStringStartsWith('pii:', $payload['customer_name']);
        $this->assertStringNotContainsString('Victim Name', $payload['customer_name']);

        // Non-PII context must be preserved unchanged.
        $this->assertSame('free', $payload['plan']);
        $this->assertSame('banned', $payload['to']);
    }

    /** @test */
    public function g6_admin_audit_log_scrubs_dirty_attributes_pii(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $target     = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($superAdmin);

        // Simulate the controller changing the email + plan.
        $target->email = 'new@example.com';
        $target->plan  = 'pro';

        AdminAuditLog::record('plan_changed', $target, ['reason' => 'admin upgrade']);

        $log = AdminAuditLog::latest('id')->first();
        $payload = $log->payload;

        // _changed should contain the dirty attributes — PII keys (email) hashed,
        // non-PII keys (plan) preserved.
        $this->assertArrayHasKey('_changed', $payload);
        $this->assertStringStartsWith('pii:', $payload['_changed']['email']);
        $this->assertStringNotContainsString('new@example.com', $payload['_changed']['email']);
        $this->assertSame('pro', $payload['_changed']['plan']);
    }

    /** @test */
    public function g6_audit_log_scrub_is_idempotent_on_already_scrubbed_values(): void
    {
        $data = ['email' => 'pii:abc123'];
        $scrubbed = AdminAuditLog::scrubPii($data);

        // Already-scrubbed values should remain unchanged (no double-hashing).
        $this->assertSame('pii:abc123', $scrubbed['email']);
    }

    /** @test */
    public function g6_audit_log_scrub_preserves_null_values(): void
    {
        $data = ['email' => null, 'plan' => 'pro'];
        $scrubbed = AdminAuditLog::scrubPii($data);

        // Null stays null — no PII to hash.
        $this->assertNull($scrubbed['email']);
        $this->assertSame('pro', $scrubbed['plan']);
    }

    /** @test */
    public function g6_anonymize_audit_pii_command_scrubs_old_logs(): void
    {
        // Insert an old audit log row with raw PII (simulating pre-Iter-010 data).
        DB::table('admin_audit_logs')->insert([
            'actor_id'    => null,
            'action'      => 'user_banned',
            'target_type' => 'App\\Models\\User',
            'target_id'   => 1,
            'payload'     => json_encode([
                'email'         => 'old-victim@example.com',
                'customer_name' => 'Old Victim',
                'plan'          => 'free',
                '_changed'      => ['email' => 'old-victim@example.com', 'plan' => 'banned'],
            ]),
            'ip'         => '127.0.0.1',
            'created_at' => now()->subYears(2), // older than 18-month retention
        ]);

        // Run the command.
        $this->artisan('exospace:anonymize-audit-pii --retention-months=18')
            ->assertSuccessful();

        $row = DB::table('admin_audit_logs')->latest('id')->first();
        $payload = json_decode($row->payload, true);

        // Top-level PII scrubbed.
        $this->assertStringStartsWith('pii:', $payload['email']);
        $this->assertStringNotContainsString('old-victim@example.com', $payload['email']);
        $this->assertStringStartsWith('pii:', $payload['customer_name']);
        $this->assertStringNotContainsString('Old Victim', $payload['customer_name']);

        // Nested _changed PII also scrubbed.
        $this->assertStringStartsWith('pii:', $payload['_changed']['email']);

        // Non-PII preserved.
        $this->assertSame('free', $payload['plan']);
        $this->assertSame('banned', $payload['_changed']['plan']);
    }

    /** @test */
    public function g6_anonymize_audit_pii_command_respects_retention_window(): void
    {
        // Insert a RECENT audit log row with raw PII.
        DB::table('admin_audit_logs')->insert([
            'actor_id'    => null,
            'action'      => 'user_banned',
            'target_type' => 'App\\Models\\User',
            'target_id'   => 1,
            'payload'     => json_encode(['email' => 'recent@example.com']),
            'ip'         => '127.0.0.1',
            'created_at' => now()->subDays(7), // within retention window
        ]);

        $this->artisan('exospace:anonymize-audit-pii --retention-months=18')
            ->assertSuccessful();

        $row = DB::table('admin_audit_logs')->latest('id')->first();
        $payload = json_decode($row->payload, true);

        // Recent row NOT scrubbed — still has raw email.
        $this->assertSame('recent@example.com', $payload['email']);
    }

    /** @test */
    public function g6_anonymize_audit_pii_command_is_idempotent(): void
    {
        // Insert an old row that's ALREADY been scrubbed.
        DB::table('admin_audit_logs')->insert([
            'actor_id'    => null,
            'action'      => 'user_banned',
            'target_type' => 'App\\Models\\User',
            'target_id'   => 1,
            'payload'     => json_encode(['email' => 'pii:alreadydone']),
            'ip'         => '127.0.0.1',
            'created_at' => now()->subYears(2),
        ]);

        $this->artisan('exospace:anonymize-audit-pii --retention-months=18')
            ->assertSuccessful();

        $row = DB::table('admin_audit_logs')->latest('id')->first();
        $payload = json_decode($row->payload, true);

        // Already-scrubbed value preserved (no double-hashing).
        $this->assertSame('pii:alreadydone', $payload['email']);
    }
}

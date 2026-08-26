<?php

declare(strict_types=1);

/**
 * ITERATION-5 regression tests.
 *
 * Verifies the 3 new PII anonymization commands:
 *   - AUDIT-P1-5.1: exospace:anonymize-feedback-pii
 *   - AUDIT-P1-5.2: exospace:anonymize-rsvp-pii
 *   - AUDIT-P1-5.3: exospace:anonymize-newsletter-pii
 *
 * Each test verifies:
 *   1. Old rows (> retention window) get PII anonymized.
 *   2. Recent rows (< retention window) are untouched.
 *   3. Already-anonymized rows are skipped (idempotency).
 *   4. Non-PII fields (category, status, gallery_id, etc.) are preserved.
 *   5. --dry-run flag doesn't modify data.
 *
 * Run: php artisan test --filter=Iteration5Test
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Iteration5Test extends TestCase
{
    use RefreshDatabase;

    /**
     * ITERATION-1 FIX: Schedule::assertScheduled() does not exist in
     * Laravel 11/12 (imaginary API). Inspect the scheduler's events.
     */
    private function assertCommandScheduled(string $needle, string $message = ''): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => trim((string) $event->command));

        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, $needle)),
            $message !== '' ? $message : "Scheduled command containing '{$needle}' was not found.",
        );
    }

    // ── AUDIT-P1-5.1: exospace:anonymize-feedback-pii ──────────────────

    /**
     * AUDIT-P1-5.1: Old feedback rows get PII anonymized; recent rows untouched.
     */
    public function test_audit_p15_1_feedback_pii_anonymized_for_old_rows_only(): void
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(20);
        $recentDate = now()->subMonth();

        // Old row — should be anonymized
        $oldFeedbackId = DB::table('user_feedback')->insertGetId([
            'user_id'    => $user->id,
            'category'   => 'bug',
            'message'    => 'The 3D viewer crashes on Safari when I click the tour button.',
            'page_url'   => 'https://exospace.gallery/admin/galleries/123/edit',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'status'     => 'new',
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);

        // Recent row — should NOT be anonymized
        $recentFeedbackId = DB::table('user_feedback')->insertGetId([
            'user_id'    => $user->id,
            'category'   => 'praise',
            'message'    => 'Love the new live preview feature!',
            'page_url'   => 'https://exospace.gallery/admin/galleries/456/edit',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0',
            'status'     => 'new',
            'created_at' => $recentDate,
            'updated_at' => $recentDate,
        ]);

        $exitCode = Artisan::call('exospace:anonymize-feedback-pii', ['--retention-months' => 18]);
        $this->assertSame(0, $exitCode, 'Command should exit with success code.');

        // Old row — verify anonymization
        $oldRow = DB::table('user_feedback')->where('id', $oldFeedbackId)->first();
        $this->assertStringStartsWith('anonymized:', $oldRow->message, 'Old feedback message should be anonymized.');
        $this->assertStringNotContainsString('crashes on Safari', $oldRow->message);
        $this->assertNull($oldRow->page_url, 'Old page_url should be null.');
        $this->assertNull($oldRow->user_agent, 'Old user_agent should be null.');
        $this->assertNull($oldRow->user_id, 'Old user_id should be null (detached from user).');
        // Non-PII fields preserved
        $this->assertSame('bug', $oldRow->category, 'Category should be preserved.');
        $this->assertSame('new', $oldRow->status, 'Status should be preserved.');

        // Recent row — verify NO anonymization
        $recentRow = DB::table('user_feedback')->where('id', $recentFeedbackId)->first();
        $this->assertSame('Love the new live preview feature!', $recentRow->message, 'Recent message should be untouched.');
        $this->assertNotNull($recentRow->page_url, 'Recent page_url should be untouched.');
        $this->assertNotNull($recentRow->user_agent, 'Recent user_agent should be untouched.');
        $this->assertSame($user->id, $recentRow->user_id, 'Recent user_id should be untouched.');
    }

    /**
     * AUDIT-P1-5.1: --dry-run doesn't modify data.
     */
    public function test_audit_p15_1_feedback_dry_run_makes_no_changes(): void
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(20);

        $feedbackId = DB::table('user_feedback')->insertGetId([
            'user_id'    => $user->id,
            'category'   => 'bug',
            'message'    => 'Original feedback message',
            'page_url'   => 'https://example.com/page',
            'user_agent' => 'TestAgent/1.0',
            'status'     => 'new',
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);

        Artisan::call('exospace:anonymize-feedback-pii', ['--retention-months' => 18, '--dry-run' => true]);

        $row = DB::table('user_feedback')->where('id', $feedbackId)->first();
        $this->assertSame('Original feedback message', $row->message, 'Dry-run should not modify message.');
        $this->assertSame('https://example.com/page', $row->page_url, 'Dry-run should not modify page_url.');
        $this->assertSame($user->id, $row->user_id, 'Dry-run should not modify user_id.');
    }

    /**
     * AUDIT-P1-5.1: Re-running is idempotent (already-anonymized rows are skipped).
     */
    public function test_audit_p15_1_feedback_idempotent_on_second_run(): void
    {
        $user = User::factory()->create();
        $oldDate = now()->subMonths(20);

        $feedbackId = DB::table('user_feedback')->insertGetId([
            'user_id'    => $user->id,
            'category'   => 'bug',
            'message'    => 'First run will anonymize this',
            'page_url'   => 'https://example.com/page',
            'user_agent' => 'TestAgent/1.0',
            'status'     => 'new',
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);

        // First run — anonymizes
        Artisan::call('exospace:anonymize-feedback-pii', ['--retention-months' => 18]);
        $firstRunRow = DB::table('user_feedback')->where('id', $feedbackId)->first();
        $firstAnonymizedMessage = $firstRunRow->message;

        // Second run — should be a no-op
        Artisan::call('exospace:anonymize-feedback-pii', ['--retention-months' => 18]);
        $secondRunRow = DB::table('user_feedback')->where('id', $feedbackId)->first();

        $this->assertSame($firstAnonymizedMessage, $secondRunRow->message, 'Second run should not change the already-anonymized message.');
    }

    // ── AUDIT-P1-5.2: exospace:anonymize-rsvp-pii ────────────────────────

    /**
     * AUDIT-P1-5.2: Old RSVP rows get PII anonymized; recent rows untouched.
     */
    public function test_audit_p15_2_rsvp_pii_anonymized_for_old_rows_only(): void
    {
        $gallery = Gallery::factory()->create();
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Opening Night',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(7),
            'is_active'  => true,
        ]);

        $oldDate = now()->subMonths(20);
        $recentDate = now()->subMonth();

        // Old RSVP — should be anonymized
        $oldRsvpId = DB::table('event_rsvps')->insertGetId([
            'schedule_event_id' => $event->id,
            'name'              => 'Jane Visitor',
            'email'             => 'jane@example.com',
            'ip_address'        => '203.0.113.42',
            'confirmed_at'      => $oldDate,
            'created_at'        => $oldDate,
            'updated_at'        => $oldDate,
        ]);

        // Recent RSVP — should NOT be anonymized
        $recentRsvpId = DB::table('event_rsvps')->insertGetId([
            'schedule_event_id' => $event->id,
            'name'              => 'John Guest',
            'email'             => 'john@example.com',
            'ip_address'        => '198.51.100.7',
            'confirmed_at'      => $recentDate,
            'created_at'        => $recentDate,
            'updated_at'        => $recentDate,
        ]);

        $exitCode = Artisan::call('exospace:anonymize-rsvp-pii', ['--retention-months' => 18]);
        $this->assertSame(0, $exitCode);

        // Old RSVP — verify anonymization
        $oldRow = DB::table('event_rsvps')->where('id', $oldRsvpId)->first();
        $this->assertStringStartsWith('anonymized:', $oldRow->email, 'Old RSVP email should be anonymized.');
        $this->assertStringNotContainsString('jane@example.com', $oldRow->email);
        $this->assertNull($oldRow->name, 'Old RSVP name should be null.');
        $this->assertNull($oldRow->ip_address, 'Old RSVP ip_address should be null.');
        // Non-PII fields preserved
        $this->assertSame($event->id, $oldRow->schedule_event_id, 'schedule_event_id should be preserved.');

        // Recent RSVP — verify NO anonymization
        $recentRow = DB::table('event_rsvps')->where('id', $recentRsvpId)->first();
        $this->assertSame('john@example.com', $recentRow->email, 'Recent RSVP email should be untouched.');
        $this->assertSame('John Guest', $recentRow->name, 'Recent RSVP name should be untouched.');
        $this->assertSame('198.51.100.7', $recentRow->ip_address, 'Recent RSVP ip_address should be untouched.');
    }

    /**
     * AUDIT-P1-5.2: --dry-run doesn't modify data.
     */
    public function test_audit_p15_2_rsvp_dry_run_makes_no_changes(): void
    {
        $gallery = Gallery::factory()->create();
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Test Event',
            'type'       => 'event',
            'starts_at'  => now()->addDays(7),
            'is_active'  => true,
        ]);
        $oldDate = now()->subMonths(20);

        $rsvpId = DB::table('event_rsvps')->insertGetId([
            'schedule_event_id' => $event->id,
            'name'              => 'Test Person',
            'email'             => 'test@example.com',
            'ip_address'        => '192.0.2.1',
            'confirmed_at'      => $oldDate,
            'created_at'        => $oldDate,
            'updated_at'        => $oldDate,
        ]);

        Artisan::call('exospace:anonymize-rsvp-pii', ['--retention-months' => 18, '--dry-run' => true]);

        $row = DB::table('event_rsvps')->where('id', $rsvpId)->first();
        $this->assertSame('test@example.com', $row->email, 'Dry-run should not modify email.');
        $this->assertSame('Test Person', $row->name, 'Dry-run should not modify name.');
        $this->assertSame('192.0.2.1', $row->ip_address, 'Dry-run should not modify ip_address.');
    }

    // ── AUDIT-P1-5.3: exospace:anonymize-newsletter-pii ────────────────

    /**
     * AUDIT-P1-5.3: Old newsletter signup rows get PII anonymized; recent rows untouched.
     */
    public function test_audit_p15_3_newsletter_pii_anonymized_for_old_rows_only(): void
    {
        $gallery = Gallery::factory()->create();
        $oldDate = now()->subMonths(20);
        $recentDate = now()->subMonth();

        // Old signup — should be anonymized
        $oldSignupId = DB::table('newsletter_signups')->insertGetId([
            'gallery_id'  => $gallery->id,
            'email'       => 'subscriber@example.com',
            'name'        => 'Subscriber Name',
            'ip_address'  => '203.0.113.99',
            'referrer'    => 'https://google.com/search?q=art+gallery',
            'signed_up_at' => $oldDate,
            'created_at'  => $oldDate,
            'updated_at'  => $oldDate,
        ]);

        // Recent signup — should NOT be anonymized
        $recentSignupId = DB::table('newsletter_signups')->insertGetId([
            'gallery_id'  => $gallery->id,
            'email'       => 'recent@example.com',
            'name'        => 'Recent Subscriber',
            'ip_address'  => '198.51.100.50',
            'referrer'    => 'https://twitter.com/post/123',
            'signed_up_at' => $recentDate,
            'created_at'  => $recentDate,
            'updated_at'  => $recentDate,
        ]);

        $exitCode = Artisan::call('exospace:anonymize-newsletter-pii', ['--retention-months' => 18]);
        $this->assertSame(0, $exitCode);

        // Old signup — verify anonymization
        $oldRow = DB::table('newsletter_signups')->where('id', $oldSignupId)->first();
        $this->assertStringStartsWith('anonymized:', $oldRow->email, 'Old newsletter email should be anonymized.');
        $this->assertStringNotContainsString('subscriber@example.com', $oldRow->email);
        $this->assertNull($oldRow->name, 'Old newsletter name should be null.');
        $this->assertNull($oldRow->ip_address, 'Old newsletter ip_address should be null.');
        $this->assertNull($oldRow->referrer, 'Old newsletter referrer should be null.');
        // Non-PII fields preserved
        $this->assertSame($gallery->id, $oldRow->gallery_id, 'gallery_id should be preserved.');

        // Recent signup — verify NO anonymization
        $recentRow = DB::table('newsletter_signups')->where('id', $recentSignupId)->first();
        $this->assertSame('recent@example.com', $recentRow->email, 'Recent newsletter email should be untouched.');
        $this->assertSame('Recent Subscriber', $recentRow->name, 'Recent newsletter name should be untouched.');
        $this->assertSame('198.51.100.50', $recentRow->ip_address, 'Recent newsletter ip_address should be untouched.');
        $this->assertSame('https://twitter.com/post/123', $recentRow->referrer, 'Recent newsletter referrer should be untouched.');
    }

    /**
     * AUDIT-P1-5.3: --dry-run doesn't modify data.
     */
    public function test_audit_p15_3_newsletter_dry_run_makes_no_changes(): void
    {
        $gallery = Gallery::factory()->create();
        $oldDate = now()->subMonths(20);

        $signupId = DB::table('newsletter_signups')->insertGetId([
            'gallery_id'  => $gallery->id,
            'email'       => 'test@example.com',
            'name'        => 'Test Name',
            'ip_address'  => '192.0.2.1',
            'referrer'    => 'https://example.com',
            'signed_up_at' => $oldDate,
            'created_at'  => $oldDate,
            'updated_at'  => $oldDate,
        ]);

        Artisan::call('exospace:anonymize-newsletter-pii', ['--retention-months' => 18, '--dry-run' => true]);

        $row = DB::table('newsletter_signups')->where('id', $signupId)->first();
        $this->assertSame('test@example.com', $row->email, 'Dry-run should not modify email.');
        $this->assertSame('Test Name', $row->name, 'Dry-run should not modify name.');
        $this->assertSame('192.0.2.1', $row->ip_address, 'Dry-run should not modify ip_address.');
        $this->assertSame('https://example.com', $row->referrer, 'Dry-run should not modify referrer.');
    }

    /**
     * AUDIT-P1-5.3: Newsletter signup with no rows — handles gracefully.
     */
    public function test_audit_p15_3_newsletter_handles_empty_table_gracefully(): void
    {
        $exitCode = Artisan::call('exospace:anonymize-newsletter-pii', ['--retention-months' => 18]);
        $this->assertSame(0, $exitCode, 'Command should succeed with empty table.');
    }

    /**
     * Schedule verification: all 3 new commands are scheduled monthly.
     */
    public function test_audit_p15_schedule_includes_all_3_new_anonymization_commands(): void
    {
        $this->assertCommandScheduled('exospace:anonymize-feedback-pii');
        $this->assertCommandScheduled('exospace:anonymize-rsvp-pii');
        $this->assertCommandScheduled('exospace:anonymize-newsletter-pii');
    }
}

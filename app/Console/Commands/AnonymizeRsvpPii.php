<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-5 (AUDIT-P1-5.2): PII retention for the event_rsvps table.
 *
 * The event_rsvps table stores RSVP submissions from visitors who want to
 * attend gallery events. Each entry has:
 *   - name: the visitor's name (PII)
 *   - email: the visitor's email (PII — used for event reminders)
 *   - ip_address: the visitor's IP (PII — used for spam analysis)
 *   - schedule_event_id: FK to the event (preserved for analytics)
 *
 * Without this command, PII is retained indefinitely — a GDPR violation.
 * Event organizers need the email + name to send reminders, but once the
 * event is long past, there's no legitimate business reason to keep the PII.
 *
 * This command anonymizes PII on event_rsvps rows older than the retention
 * window (default: 18 months):
 *   - email → 'anonymized:' + hash (preserves "same person RSVP'd to multiple
 *     events" correlation for analytics)
 *   - name → null
 *   - ip_address → null
 *
 * The schedule_event_id + confirmed_at + timestamps are preserved for
 * aggregate analytics ("how many RSVPs did this gallery get last year?").
 *
 * Schedule: monthly (1st of each month) via routes/console.php, running
 * after exospace:anonymize-feedback-pii.
 *
 * Idempotent: re-running on already-anonymized rows is a no-op.
 */
class AnonymizeRsvpPii extends Command
{
    protected $signature = 'exospace:anonymize-rsvp-pii
                            {--retention-months=18 : Anonymize PII on RSVPs older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch}';

    protected $description = 'Anonymize PII (name, email, ip_address) on old event_rsvps rows (GDPR retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("RSVP PII anonymization for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->newLine();

        if (! Schema::hasTable('event_rsvps')) {
            $this->warn("  event_rsvps table does not exist — skipping.");
            return self::SUCCESS;
        }

        // Count rows that need anonymization.
        $needsAnonymization = DB::table('event_rsvps')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('name')
                  ->orWhereNotNull('ip_address');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("  No RSVP rows need anonymization (all old rows already anonymized).");
            return self::SUCCESS;
        }

        $this->info("  Found {$needsAnonymization} RSVP rows needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} RSVP rows. No changes made.");
            return self::SUCCESS;
        }

        $anonymized = 0;
        $appId = config('app.key');

        DB::table('event_rsvps')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                  ->orWhereNotNull('name')
                  ->orWhereNotNull('ip_address');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    DB::table('event_rsvps')
                        ->where('id', $row->id)
                        ->update([
                            'email'      => 'anonymized:' . substr(hash('sha256', $appId . $row->email), 0, 16),
                            'name'       => null,
                            'ip_address' => null,
                            'updated_at' => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} RSVP rows.");

        Log::info('AnonymizeRsvpPii: complete', [
            'anonymized'        => $anonymized,
            'retention_months'  => $retentionMonths,
            'cutoff'            => $cutoff->toDateString(),
        ]);

        return self::SUCCESS;
    }
}

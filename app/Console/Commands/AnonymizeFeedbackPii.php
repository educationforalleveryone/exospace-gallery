<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-5 (AUDIT-P1-5.1): PII retention for the user_feedback table.
 *
 * The user_feedback table (M-19) stores feedback submitted via the in-app
 * feedback widget. Each entry has:
 *   - user_id: the user who submitted (nullable — but widget is admin-only)
 *   - message: the feedback text (may contain PII the user typed)
 *   - page_url: the URL the user was on (may contain query params with PII)
 *   - user_agent: browser fingerprint string
 *
 * Without this command, PII is retained indefinitely — a GDPR violation
 * (Article 5(1)(e): "kept in a form which permits identification of data
 * subjects for no longer than is necessary").
 *
 * This command anonymizes PII on user_feedback rows older than the retention
 * window (default: 18 months, matching AnonymizeTransactionPii):
 *   - message → 'anonymized:' + hash (preserves "this row had feedback" signal)
 *   - page_url → null (URLs can contain query-string PII)
 *   - user_agent → null (browser fingerprint)
 *   - user_id → null (detaches from the user — they may still exist)
 *
 * The category + status + timestamps are preserved for aggregate analytics
 * ("how much feedback did we get last quarter? what was the status breakdown?").
 *
 * Schedule: monthly (1st of each month) via routes/console.php, running
 * after exospace:anonymize-audit-pii so all PII retention happens in one
 * monthly batch.
 *
 * Idempotent: re-running on already-anonymized rows is a no-op (the
 * 'anonymized:' prefix check skips already-processed rows).
 */
class AnonymizeFeedbackPii extends Command
{
    protected $signature = 'exospace:anonymize-feedback-pii
                            {--retention-months=18 : Anonymize PII on feedback older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch}';

    protected $description = 'Anonymize PII (message, page_url, user_agent, user_id) on old user_feedback rows (GDPR retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("Feedback PII anonymization for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->newLine();

        if (! Schema::hasTable('user_feedback')) {
            $this->warn("  user_feedback table does not exist — skipping.");
            return self::SUCCESS;
        }

        // Count rows that need anonymization. A row needs anonymization if ANY
        // of its PII fields still contain raw data (not already anonymized).
        $needsAnonymization = DB::table('user_feedback')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('message', 'not like', 'anonymized:%')
                  ->orWhereNotNull('page_url')
                  ->orWhereNotNull('user_agent')
                  ->orWhereNotNull('user_id');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info("  No feedback rows need anonymization (all old rows already anonymized).");
            return self::SUCCESS;
        }

        $this->info("  Found {$needsAnonymization} feedback rows needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} feedback rows. No changes made.");
            return self::SUCCESS;
        }

        $anonymized = 0;
        $appId = config('app.key');

        DB::table('user_feedback')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('message', 'not like', 'anonymized:%')
                  ->orWhereNotNull('page_url')
                  ->orWhereNotNull('user_agent')
                  ->orWhereNotNull('user_id');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    DB::table('user_feedback')
                        ->where('id', $row->id)
                        ->update([
                            'message'    => 'anonymized:' . substr(hash('sha256', $appId . $row->message), 0, 16),
                            'page_url'   => null,
                            'user_agent' => null,
                            'user_id'    => null,
                            'updated_at' => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} feedback rows.");

        Log::info('AnonymizeFeedbackPii: complete', [
            'anonymized'        => $anonymized,
            'retention_months'  => $retentionMonths,
            'cutoff'            => $cutoff->toDateString(),
        ]);

        return self::SUCCESS;
    }
}

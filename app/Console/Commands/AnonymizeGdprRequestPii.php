<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AnonymizeGdprRequestPii extends Command
{
    protected $signature = 'exospace:anonymize-gdpr-request-pii
                            {--retention-months=18 : Anonymize PII on deletion requests older than this many months}
                            {--dry-run : Show what would be anonymized without executing}
                            {--batch-size=500 : Rows per batch}';

    protected $description = 'Anonymize PII (email, requester_ip, reason) on old gdpr_deletion_requests rows (GDPR retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("GDPR deletion request PII anonymization for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info('  Dry run: '.($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->newLine();

        if (! Schema::hasTable('gdpr_deletion_requests')) {
            $this->warn('  gdpr_deletion_requests table does not exist — skipping.');

            return self::SUCCESS;
        }

        $needsAnonymization = DB::table('gdpr_deletion_requests')
            ->where('requested_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                    ->orWhereNotNull('requester_ip')
                    ->orWhereNotNull('reason');
            })
            ->count();

        if ($needsAnonymization === 0) {
            $this->info('  No deletion request rows need anonymization (all old rows already anonymized).');

            return self::SUCCESS;
        }

        $this->info("  Found {$needsAnonymization} deletion request rows needing anonymization.");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Would anonymize {$needsAnonymization} deletion request rows. No changes made.");

            return self::SUCCESS;
        }

        $anonymized = 0;
        $appId = config('app.key');

        DB::table('gdpr_deletion_requests')
            ->where('requested_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('email', 'not like', 'anonymized:%')
                    ->orWhereNotNull('requester_ip')
                    ->orWhereNotNull('reason');
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($appId, &$anonymized) {
                foreach ($rows as $row) {
                    // The email is kept as an irreversible hash so the request
                    // record still proves which identity it was filed under;
                    // the requester IP and free-text reason go away entirely.
                    DB::table('gdpr_deletion_requests')
                        ->where('id', $row->id)
                        ->update([
                            'email' => 'anonymized:'.substr(hash('sha256', $appId.$row->email), 0, 16),
                            'requester_ip' => null,
                            'reason' => null,
                            'updated_at' => now(),
                        ]);
                    $anonymized++;
                }

                $this->info("  Anonymized batch (running total: {$anonymized})");
            });

        $this->info("  Anonymized {$anonymized} deletion request rows.");

        Log::info('AnonymizeGdprRequestPii: complete', [
            'anonymized' => $anonymized,
            'retention_months' => $retentionMonths,
            'cutoff' => $cutoff->toDateString(),
        ]);

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnonymizeAuditLogPii extends Command
{
    protected $signature = 'exospace:anonymize-audit-pii
                            {--retention-months=18 : Scrub PII on audit logs older than this many months}
                            {--dry-run : Show what would be scrubbed without executing}
                            {--batch-size=500 : Rows per batch}';

    protected $description = 'Scrub PII from old admin_audit_logs.payload (GDPR G-6 retention).';

    public function handle(): int
    {
        $retentionMonths = (int) $this->option('retention-months');
        $dryRun          = (bool) $this->option('dry-run');
        $batchSize       = (int) $this->option('batch-size');

        $cutoff = now()->subMonths($retentionMonths);

        $this->info("Audit log PII scrubbing for records older than {$retentionMonths} months (before {$cutoff->toDateString()})");
        $this->info("  Dry run: " . ($dryRun ? 'YES' : 'NO'));
        $this->info("  Batch size: {$batchSize}");
        $this->info("  PII keys: " . implode(', ', AdminAuditLog::piiKeys()));
        $this->newLine();

        // Find rows that have a non-null payload AND were created before cutoff.
        $totalRows = DB::table('admin_audit_logs')
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('payload')
            ->count();

        if ($totalRows === 0) {
            $this->info("  No audit log rows older than {$retentionMonths} months have a payload. Nothing to scrub.");
            return self::SUCCESS;
        }

        $this->info("  Found {$totalRows} audit log rows with payloads in the retention window.");

        $scrubbed = 0;
        $skipped  = 0;

        DB::table('admin_audit_logs')
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById($batchSize, function ($rows) use ($dryRun, &$scrubbed, &$skipped) {
                foreach ($rows as $row) {
                    $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
                    if (! is_array($payload)) {
                        $skipped++;
                        continue;
                    }

                    $originalJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $payload      = $this->scrubArrayRecursive($payload);
                    $newJson      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($originalJson === $newJson) {
                        // No PII to scrub in this row.
                        $skipped++;
                        continue;
                    }

                    if (! $dryRun) {
                        DB::table('admin_audit_logs')
                            ->where('id', $row->id)
                            ->update(['payload' => $newJson]);
                    }
                    $scrubbed++;
                }

                $this->info("  Processed batch (scrubbed: {$scrubbed}, skipped: {$skipped})");
            });

        $this->newLine();
        if ($dryRun) {
            $this->warn("[DRY-RUN] Would have scrubbed {$scrubbed} audit log rows. No changes made.");
        } else {
            $this->info("Scrubbed {$scrubbed} audit log rows. Skipped {$skipped} (no PII or already-scrubbed).");
        }

        Log::info('AnonymizeAuditLogPii: complete', [
            'scrubbed'         => $scrubbed,
            'skipped'          => $skipped,
            'retention_months' => $retentionMonths,
            'cutoff'           => $cutoff->toDateString(),
            'dry_run'          => $dryRun,
        ]);

        return self::SUCCESS;
    }

    private function scrubArrayRecursive(array $data): array
    {
        $appId = config('app.key');

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                // Recurse into nested arrays (e.g. _changed).
                $data[$key] = $this->scrubArrayRecursive($value);
                continue;
            }

            if (! AdminAuditLog::isPiiKey($key)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $strValue = (string) $value;

            // Skip already-scrubbed values (idempotency).
            if (str_starts_with($strValue, 'pii:') || str_starts_with($strValue, 'anonymized:')) {
                continue;
            }

            $data[$key] = 'pii:' . substr(hash('sha256', $appId . $strValue), 0, 16);
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Iteration-002 (audit 2CO-6): Regenerate invoice PDFs.
 *
 * Backfills real PDFs (via dompdf) for invoices that were created before the
 * 2CO-6 fix — i.e. invoices with .html pdf_path. Run this command once after
 * deploying Iteration-002 to convert all existing HTML invoices to PDFs.
 *
 * Usage:
 *   php artisan exospace:regenerate-invoices
 *   php artisan exospace:regenerate-invoices --limit=100
 *   php artisan exospace:regenerate-invoices --force  (regenerate ALL, even .pdf)
 *
 * The command is safe to run multiple times — it only regenerates invoices
 * with .html paths by default (idempotent). Use --force to regenerate all
 * (e.g. after updating the invoice Blade template).
 *
 * The command processes invoices in batches to avoid memory issues on large
 * tables. Default batch size is 100.
 */
class RegenerateInvoices extends Command
{
    protected $signature = 'exospace:regenerate-invoices
                            {--limit=0 : Maximum number of invoices to process (0 = all)}
                            {--force : Regenerate ALL invoices, even those with .pdf paths}
                            {--batch=100 : Number of invoices per batch}';

    protected $description = 'Regenerate invoice PDFs via dompdf (2CO-6 fix backfill). Converts .html invoices to .pdf.';

    public function handle(InvoiceGenerator $generator): int
    {
        $this->info('Exospace: Regenerate Invoices');
        $this->info('============================');

        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $batchSize = (int) $this->option('batch');

        // Verify dompdf is installed
        if (! class_exists(\Dompdf\Dompdf::class)) {
            $this->error('dompdf is not installed. Run: composer require dompdf/dompdf');
            $this->error('Without dompdf, this command will regenerate .html files (no improvement).');
            if (! $this->confirm('Continue anyway? (will produce .html files)', false)) {
                return 1;
            }
        }

        // Build the query
        $query = Invoice::query();
        if (! $force) {
            // Only invoices with .html paths (or null paths)
            $query->where(function ($q) {
                $q->whereNull('pdf_path')
                  ->orWhere('pdf_path', 'like', '%.html')
                  ->orWhere('pdf_path', '');
            });
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $totalCount = (clone $query)->count();
        if ($totalCount === 0) {
            $this->info('No invoices need regeneration. All invoices already have .pdf paths.');
            return 0;
        }

        $this->info("Found {$totalCount} invoices to regenerate.");
        if ($force) {
            $this->warn('--force specified: regenerating ALL invoices (even those with .pdf paths).');
        }
        $this->info('');

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        // Process in batches to avoid memory issues
        $query->chunkById($batchSize, function ($invoices) use ($generator, &$processed, &$succeeded, &$failed, $bar) {
            foreach ($invoices as $invoice) {
                $processed++;
                $bar->advance();

                $result = $generator->regeneratePdf($invoice);
                if ($result !== null) {
                    $succeeded++;
                } else {
                    $failed++;
                    Log::error('exospace:regenerate-invoices: failed to regenerate', [
                        'invoice_id' => $invoice->id,
                    ]);
                }
            }
        });

        $bar->finish();
        $this->info('');
        $this->info('');
        $this->info("Regeneration complete.");
        $this->info("  Processed: {$processed}");
        $this->info("  Succeeded: {$succeeded}");

        if ($failed > 0) {
            $this->warn("  Failed:    {$failed}");
            $this->warn('  Check storage/logs/laravel.log for failure details.');
            return 1;
        }

        $this->info('  Failed:    0');
        return 0;
    }
}

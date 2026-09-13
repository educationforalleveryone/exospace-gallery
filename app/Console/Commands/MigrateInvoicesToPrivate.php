<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrateInvoicesToPrivate extends Command
{
    protected $signature = 'exospace:migrate-invoices-to-private
                            {--force : Actually move files (without this flag the command is a read-only dry run)}
                            {--batch=100 : Number of invoices per batch}';

    protected $description = 'Move invoice files from the public disk to the private disk. Copy → verify → delete, idempotent, dry-run by default.';

    public function handle(): int
    {
        $this->info('Exospace: Migrate Invoices To Private Disk');
        $this->info('==========================================');

        $force = (bool) $this->option('force');
        $batchSize = max(1, (int) $this->option('batch'));

        $local = \Illuminate\Support\Facades\Storage::disk('local');
        $public = \Illuminate\Support\Facades\Storage::disk('public');

        $alreadyPrivate = 0;
        $duplicateRetired = 0;
        $toMove = [];
        $missing = 0;

        $total = Invoice::whereNotNull('pdf_path')->where('pdf_path', '!=', '')->count();
        $this->info("Invoices with a pdf_path: {$total}");
        $this->info($force ? 'Mode: MOVE (copy → verify → delete).' : 'Mode: DRY RUN (read-only — re-run with --force to move files).');
        $this->info('');

        // Chunked so a large invoice table never blows memory.
        Invoice::query()
            ->whereNotNull('pdf_path')
            ->where('pdf_path', '!=', '')
            ->orderBy('id')
            ->chunkById($batchSize, function ($invoices) use ($local, $public, $force, &$alreadyPrivate, &$duplicateRetired, &$toMove, &$missing) {
                foreach ($invoices as $invoice) {
                    $path = Str::after($invoice->pdf_path, 'storage/');

                    if ($local->exists($path)) {
                        if ($public->exists($path)) {
                            $duplicateRetired++;
                            if ($force) {
                                $public->delete($path);
                            }

                            continue;
                        }
                        $alreadyPrivate++;

                        continue;
                    }

                    if ($public->exists($path)) {
                        $toMove[] = ['invoice_id' => $invoice->id, 'path' => $path];

                        continue;
                    }

                    $missing++;
                    $this->warn("  [missing] invoice {$invoice->id}: {$path} not found on either disk.");
                }
            });

        $this->info("  Already on private disk : {$alreadyPrivate}");
        $this->info('  To move                 : '.count($toMove));
        $this->info("  Public duplicates to retire: {$duplicateRetired}".($duplicateRetired > 0 && ! $force ? ' (dry run — not deleted yet)' : ''));
        $this->info("  Missing on both disks   : {$missing}");
        $this->info('');

        if (empty($toMove) && $duplicateRetired === 0) {
            $this->info('Nothing to move. Public-disk invoice exposure is fully retired.');

            return 0;
        }

        if (! $force) {
            foreach (array_slice($toMove, 0, 20) as $entry) {
                $this->line("  would move: {$entry['path']} (invoice {$entry['invoice_id']})");
            }
            if (count($toMove) > 20) {
                $this->line('  … and '.(count($toMove) - 20).' more.');
            }
            $this->info('');
            $this->info('Dry run complete. Re-run with --force to move '.count($toMove).' file(s)'
                .($duplicateRetired > 0 ? ' and delete '.$duplicateRetired.' redundant public duplicate(s).' : '.'));

            return 0;
        }

        $moved = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar(count($toMove));
        $bar->start();

        foreach ($toMove as $entry) {
            $bar->advance();

            try {
                $content = $public->get($entry['path']);
                if ($content === null) {
                    throw new \RuntimeException('public disk returned null contents');
                }

                // Write the private copy FIRST.
                $local->put($entry['path'], $content);

                if ($local->size($entry['path']) !== $public->size($entry['path'])) {
                    $local->delete($entry['path']);
                    throw new \RuntimeException('size verification failed after copy');
                }

                $public->delete($entry['path']);
                $moved++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("  [failed] invoice {$entry['invoice_id']}: {$entry['path']} — {$e->getMessage()}");
                Log::error('exospace:migrate-invoices-to-private: move failed', [
                    'invoice_id' => $entry['invoice_id'],
                    'path' => $entry['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Migration complete.');
        $this->info("  Moved to private disk: {$moved}");

        if ($failed > 0) {
            $this->warn("  Failed               : {$failed} (public files left intact — check storage/logs/laravel.log)");

            return 1;
        }

        $this->info('  Failed               : 0');
        $this->info('');
        $this->info('Direct /storage/invoices/… URLs now 404. Invoice downloads remain available through the authorized /billing/invoice/{id} endpoint.');

        return 0;
    }
}

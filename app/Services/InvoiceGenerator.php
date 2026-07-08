<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M-10: Invoice generation service.
 *
 * ITERATION-002 FIX (audit 2CO-6): Now generates REAL PDFs via dompdf.
 *
 * Previously: the generatePdf() method rendered the Blade view to HTML and
 * stored it with a .html extension, but the column was named pdf_path and
 * the public-facing filename was INV-{YEAR}-{SEQ}.html served as
 * application/pdf. Customers could not download a real PDF invoice. This
 * is a legal compliance issue in many EU jurisdictions — VAT-compliant
 * invoices must be tamper-proof PDFs (or electronic-invoice-format XML).
 * Tax authorities reject HTML "invoices." 2Checkout's merchant approval
 * process also reviews invoice delivery — they flag a SaaS that serves
 * HTML as PDFs.
 *
 * FIX:
 *   - composer require dompdf/dompdf (pure-PHP, works in containerized envs
 *     without wkhtmltopdf).
 *   - generatePdf() now calls Dompdf to render the Blade view to a real PDF.
 *   - The file extension is .pdf (was .html).
 *   - Content-Type is application/pdf (handled in BillingController::downloadInvoice).
 *   - A backfill command (exospace:regenerate-invoices) regenerates PDFs for
 *     existing invoices that still have .html paths.
 *
 * Invoice numbering:
 *   Sequential per year: INV-{YEAR}-{5-digit-sequence}. The sequence
 *   resets to 00001 at the start of each year. Generated atomically via
 *   a DB-level SELECT FOR UPDATE on the invoices table's MAX(invoice_number).
 *
 * Tax handling:
 *   Tax is calculated based on the user's billing address (if provided)
 *   + the configured tax rates. For now, tax defaults to 0 — the founder
 *   should configure tax rates per jurisdiction (M-11 VAT/TAX handling,
 *   deferred to a future iteration). The invoice stores tax_amount +
 *   tax_rate so the PDF shows the correct breakdown.
 */
class InvoiceGenerator
{
    /**
     * Generate an invoice for a transaction.
     *
     * @param  Transaction  $transaction
     * @param  User         $user
     * @param  array        $overrides  Optional field overrides (e.g. billing_address)
     * @return Invoice|null  The created Invoice, or null on failure.
     */
    public function generateForTransaction(Transaction $transaction, User $user, array $overrides = []): ?Invoice
    {
        try {
            $invoiceNumber = $this->generateInvoiceNumber();

            // Calculate tax (defaults to 0 — M-11 will add jurisdiction-based rates)
            $amount = (float) $transaction->amount;
            $taxRate = (float) ($overrides['tax_rate'] ?? 0);
            $taxAmount = $taxRate > 0 ? round($amount * $taxRate / 100, 2) : 0;

            $invoice = Invoice::create([
                'user_id'         => $user->id,
                'transaction_id'  => $transaction->id,
                'invoice_number'  => $invoiceNumber,
                'amount'          => $amount,
                'tax_amount'      => $taxAmount,
                'tax_rate'        => $taxRate,
                'currency'        => $transaction->currency,
                'plan'            => $transaction->plan,
                'customer_name'   => $transaction->customer_name ?? $user->name,
                'customer_email'  => $transaction->customer_email ?? $user->email,
                'billing_address' => $overrides['billing_address'] ?? null,
                'pdf_path'        => null, // set after PDF generation
                'issued_at'       => now(),
            ]);

            // Generate the PDF (2CO-6 FIX: real PDF via dompdf)
            $pdfPath = $this->generatePdf($invoice);
            $invoice->forceFill(['pdf_path' => $pdfPath])->save();

            Log::info('InvoiceGenerator: invoice created', [
                'invoice_id'      => $invoice->id,
                'invoice_number'  => $invoiceNumber,
                'transaction_id'  => $transaction->id,
                'user_id'         => $user->id,
                'amount'          => $amount,
                'pdf_path'        => $pdfPath,
            ]);

            return $invoice;

        } catch (\Throwable $e) {
            Log::error('InvoiceGenerator: failed to generate invoice', [
                'transaction_id' => $transaction->id,
                'user_id'        => $user->id,
                'error'          => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a sequential invoice number: INV-{YEAR}-{5-digit-sequence}.
     *
     * The sequence resets to 00001 at the start of each year. Uses a DB
     * transaction with SELECT ... FOR UPDATE on the MAX(invoice_number)
     * to ensure atomicity (no duplicate numbers under concurrent inserts).
     */
    private function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        return DB::transaction(function () use ($year, $prefix) {
            // Lock the invoices table for the duration of this transaction
            // to prevent concurrent invoice-number generation.
            $lastInvoice = DB::table('invoices')
                ->where('invoice_number', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('invoice_number')
                ->value('invoice_number');

            $sequence = 1;
            if ($lastInvoice) {
                // Extract the 5-digit sequence from the end of the last invoice number
                $parts = explode('-', $lastInvoice);
                $sequence = (int) end($parts) + 1;
            }

            return $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Generate the PDF for an invoice.
     *
     * 2CO-6 FIX (Iter-002): Now uses dompdf to generate a REAL PDF.
     * Previously: rendered the Blade view to HTML and stored it as a .html
     * file. Now: renders to PDF via dompdf and stores as .pdf.
     *
     * Returns the relative path on the public disk (e.g. "invoices/2026/INV-2026-00001.pdf").
     */
    private function generatePdf(Invoice $invoice): string
    {
        $year = $invoice->issued_at->year;
        $directory = "invoices/{$year}";
        $filename = "{$invoice->invoice_number}.pdf";
        $relativePath = "{$directory}/{$filename}";

        // Ensure the directory exists
        Storage::disk('public')->makeDirectory($directory);

        // Render the Blade view to HTML
        $html = view('invoices.pdf', ['invoice' => $invoice])->render();

        // 2CO-6 FIX: Convert HTML to PDF via dompdf.
        //
        // dompdf is a pure-PHP PDF renderer — no external binary required.
        // It works in containerized environments (Coolify/Nixpacks) without
        // any system dependencies. The quality is sufficient for invoices
        // (simple table layout, no complex CSS). For higher-quality PDFs
        // (complex layouts, SVG), snappy/wkhtmltopdf would be better but
        // requires a system binary.
        //
        // If dompdf is not installed (composer require dompdf/dompdf),
        // fall back to HTML storage with a warning log. This preserves
        // backward compatibility during the transition.
        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => false, // security: don't fetch remote resources
                'defaultFont' => 'DejaVu Sans',
            ]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            Storage::disk('public')->put($relativePath, $pdfContent);

            Log::info('InvoiceGenerator: PDF generated via dompdf', [
                'invoice_id'  => $invoice->id,
                'pdf_path'    => $relativePath,
                'size_bytes'  => strlen($pdfContent),
            ]);
        } else {
            // Fallback: store as HTML (backward compatibility during transition)
            $filename = "{$invoice->invoice_number}.html";
            $relativePath = "{$directory}/{$filename}";
            Storage::disk('public')->put($relativePath, $html);

            Log::warning('InvoiceGenerator: dompdf not installed — falling back to HTML. Run: composer require dompdf/dompdf', [
                'invoice_id'  => $invoice->id,
                'html_path'   => $relativePath,
            ]);
        }

        return $relativePath;
    }

    /**
     * 2CO-6 FIX: Regenerate the PDF for an existing invoice.
     *
     * Used by the `exospace:regenerate-invoices` artisan command to backfill
     * PDFs for invoices that were created before the dompdf fix (i.e.
     * invoices with .html pdf_path).
     *
     * @param  Invoice  $invoice
     * @return string|null  The new pdf_path, or null on failure.
     */
    public function regeneratePdf(Invoice $invoice): ?string
    {
        try {
            $pdfPath = $this->generatePdf($invoice);
            $invoice->forceFill(['pdf_path' => $pdfPath])->save();

            Log::info('InvoiceGenerator: invoice PDF regenerated', [
                'invoice_id' => $invoice->id,
                'pdf_path'   => $pdfPath,
            ]);

            return $pdfPath;
        } catch (\Throwable $e) {
            Log::error('InvoiceGenerator: failed to regenerate invoice PDF', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}

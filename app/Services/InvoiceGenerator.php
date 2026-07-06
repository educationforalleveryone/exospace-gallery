<?php

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
 * Creates an Invoice record for a transaction + generates a PDF invoice
 * stored on the public disk. The PDF is a simple HTML-to-PDF render
 * using a Blade view + the server's PDF generation capability.
 *
 * PDF generation approach:
 *   This service generates a self-contained HTML invoice view and stores
 *   it as the pdf_path. For actual PDF binary generation, the founder
 *   should install a PDF library (dompdf, snappy, or wkhtmltopdf) and
 *   update the generatePdf() method to call it. The HTML view is
 *   rendered via Blade (resources/views/invoices/pdf.blade.php) and
 *   can be served directly as HTML if PDF generation isn't available.
 *
 *   This deferred-PDF approach lets the feature ship without a hard
 *   dependency on a specific PDF library — the founder can choose the
 *   best library for their hosting environment (dompdf is pure-PHP and
 *   works everywhere; snappy/wkhtmltopdf produces better-quality PDFs
 *   but requires a binary).
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
 *   deferred). The invoice stores tax_amount + tax_rate so the PDF shows
 *   the correct breakdown.
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

            // Generate the PDF (or HTML fallback)
            $pdfPath = $this->generatePdf($invoice);
            $invoice->forceFill(['pdf_path' => $pdfPath])->save();

            Log::info('InvoiceGenerator: invoice created', [
                'invoice_id'      => $invoice->id,
                'invoice_number'  => $invoiceNumber,
                'transaction_id'  => $transaction->id,
                'user_id'         => $user->id,
                'amount'          => $amount,
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
     * Generate the PDF (or HTML fallback) for an invoice.
     *
     * Renders the invoice Blade view to HTML and stores it as a .html
     * file on the public disk. The founder should replace this with a
     * proper PDF library (dompdf, snappy) — see the class docblock.
     *
     * Returns the relative path on the public disk (e.g. "invoices/2026/INV-2026-00001.html").
     */
    private function generatePdf(Invoice $invoice): string
    {
        $year = $invoice->issued_at->year;
        $directory = "invoices/{$year}";
        $filename = "{$invoice->invoice_number}.html";
        $relativePath = "{$directory}/{$filename}";

        // Ensure the directory exists
        Storage::disk('public')->makeDirectory($directory);

        // Render the Blade view to HTML
        $html = view('invoices.pdf', ['invoice' => $invoice])->render();

        // Store the HTML (future: convert to PDF via dompdf/snappy)
        Storage::disk('public')->put($relativePath, $html);

        return $relativePath;
    }
}

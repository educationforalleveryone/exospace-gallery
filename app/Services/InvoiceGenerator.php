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

class InvoiceGenerator
{
    public function __construct(
        private readonly TaxService $taxService,
    ) {}

    public function generateForTransaction(Transaction $transaction, User $user, array $overrides = []): ?Invoice
    {
        try {
            $invoiceNumber = $this->generateInvoiceNumber();

            $amount = (float) $transaction->amount;

            $taxRate = (float) ($overrides['tax_rate'] ?? 0);
            $taxAmount = $taxRate > 0 ? round($amount * $taxRate / 100, 2) : 0;
            $reverseCharge = false;
            $taxCountryCode = $overrides['customer_country'] ?? null;
            $customerVatNumber = $overrides['customer_vat_number'] ?? null;
            $supplierVatNumber = TaxService::supplierVatNumber();

            if ($taxRate === 0.0 && empty($overrides['tax_rate'])) {
                $customerIp = $overrides['customer_ip'] ?? request()?->ip() ?? '0.0.0.0';
                $taxBreakdown = $this->taxService->calculateTax(
                    $customerIp,
                    $amount,
                    $overrides['customer_country'] ?? null,
                    $customerVatNumber,
                );
                $taxRate = $taxBreakdown['rate'];
                $taxAmount = $taxBreakdown['amount'];
                $reverseCharge = $taxBreakdown['is_reverse_charge'];
                $taxCountryCode = $taxBreakdown['country'];
            }

            $billingType = $overrides['billing_type']
                ?? ($user->subscription_id && $user->plan === $transaction->plan
                    ? 'subscription'
                    : 'one_time');

            $invoice = Invoice::create([
                'user_id'              => $user->id,
                'transaction_id'       => $transaction->id,
                'invoice_number'       => $invoiceNumber,
                'amount'               => $amount,
                'tax_amount'           => $taxAmount,
                'tax_rate'             => $taxRate,
                'currency'             => $transaction->currency,
                'plan'                 => $transaction->plan,
                'billing_type'         => $billingType,
                'customer_name'        => $transaction->customer_name ?? $user->name,
                'customer_email'       => $transaction->customer_email ?? $user->email,
                'billing_address'      => $overrides['billing_address'] ?? null,
                'customer_vat_number'  => $customerVatNumber,
                'supplier_vat_number'  => $supplierVatNumber,
                'tax_country_code'     => $taxCountryCode,
                'reverse_charge'       => $reverseCharge,
                'pdf_path'             => null, // set after PDF generation
                'issued_at'            => now(),
            ]);

            // Generate the PDF (2CO-6 FIX: real PDF via dompdf)
            $pdfPath = $this->generatePdf($invoice);
            $invoice->forceFill(['pdf_path' => $pdfPath])->save();

            Log::info('InvoiceGenerator: invoice created', [
                'invoice_id'        => $invoice->id,
                'invoice_number'    => $invoiceNumber,
                'transaction_id'    => $transaction->id,
                'user_id'           => $user->id,
                'amount'            => $amount,
                'tax_rate'          => $taxRate,
                'tax_amount'        => $taxAmount,
                'reverse_charge'    => $reverseCharge,
                'tax_country_code'  => $taxCountryCode,
                'pdf_path'          => $pdfPath,
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

    private function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        return DB::transaction(function () use ($year, $prefix) {
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

    private function generatePdf(Invoice $invoice): string
    {
        $year = $invoice->issued_at->year;
        $directory = "invoices/{$year}";
        $filename = "{$invoice->invoice_number}.pdf";
        $relativePath = "{$directory}/{$filename}";

        // Ensure the directory exists (private disk — see M-1 above)
        Storage::disk('local')->makeDirectory($directory);

        // Render the Blade view to HTML
        $html = view('invoices.pdf', ['invoice' => $invoice])->render();

        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => false, // security: don't fetch remote resources
                'defaultFont' => 'DejaVu Sans',
            ]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            Storage::disk('local')->put($relativePath, $pdfContent);

            Log::info('InvoiceGenerator: PDF generated via dompdf', [
                'invoice_id'  => $invoice->id,
                'pdf_path'    => $relativePath,
                'size_bytes'  => strlen($pdfContent),
            ]);
        } else {
            // Fallback: store as HTML (backward compatibility during transition)
            $filename = "{$invoice->invoice_number}.html";
            $relativePath = "{$directory}/{$filename}";
            Storage::disk('local')->put($relativePath, $html);

            Log::warning('InvoiceGenerator: dompdf not installed — falling back to HTML. Run: composer require dompdf/dompdf', [
                'invoice_id'  => $invoice->id,
                'html_path'   => $relativePath,
            ]);
        }

        return $relativePath;
    }

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

{{-- M-10: Invoice PDF/HTML template.
    Rendered by InvoiceGenerator::generatePdf() and stored on the public disk.
    The founder should replace the HTML storage with a proper PDF library
    (dompdf/snappy) — this Blade view is structured to be PDF-compatible
    (simple table layout, no external CSS/JS, print-friendly). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1f2937; margin: 0; padding: 40px; font-size: 14px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
        .logo { font-size: 24px; font-weight: bold; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .invoice-meta { text-align: right; }
        .invoice-meta h1 { font-size: 28px; margin: 0 0 8px 0; color: #1f2937; }
        .invoice-meta .number { font-size: 14px; color: #6b7280; }
        .sections { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .section { flex: 1; }
        .section-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 8px; }
        .section-content { font-size: 13px; line-height: 1.6; color: #4b5563; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        .table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; padding: 12px 16px; border-bottom: 2px solid #e5e7eb; }
        .table td { padding: 16px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .table .amount { text-align: right; font-variant-numeric: tabular-nums; }
        .totals { margin-left: auto; width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .totals-row.total { border-top: 2px solid #e5e7eb; margin-top: 8px; padding-top: 16px; font-size: 18px; font-weight: bold; }
        .footer { margin-top: 60px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; text-align: center; }
        @media print { body { padding: 20px; } }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="logo">EXOSPACE</div>
            <p style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                @if(config('app.business_address'))
                    {{ config('app.business_address') }}
                @else
                    Exospace Gallery
                @endif
            </p>
        </div>
        <div class="invoice-meta">
            <h1>INVOICE</h1>
            <div class="number">{{ $invoice->invoice_number }}</div>
            <div class="number" style="margin-top: 4px;">Issued: {{ $invoice->issued_at->format('M j, Y') }}</div>
        </div>
    </div>

    <div class="sections">
        <div class="section">
            <div class="section-label">Billed To</div>
            <div class="section-content">
                <strong>{{ $invoice->customer_name }}</strong><br>
                {{ $invoice->customer_email }}
                @if($invoice->billing_address)
                    <br>{{ $invoice->billing_address }}
                @endif
            </div>
        </div>
        <div class="section" style="text-align: right;">
            <div class="section-label">Payment Details</div>
            <div class="section-content">
                Plan: <strong style="text-transform: capitalize;">{{ $invoice->plan }}</strong><br>
                Currency: {{ $invoice->currency }}<br>
                @if($invoice->transaction_id)
                    Transaction: {{ $invoice->transaction->invoice_id ?? 'N/A' }}
                @endif
            </div>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>Exospace {{ ucfirst($invoice->plan) }} Plan</strong><br>
                    <span style="font-size: 12px; color: #9ca3af;">
                        @if($invoice->transaction && $invoice->transaction->subscription_id)
                            Monthly subscription
                        @else
                            One-time purchase — Lifetime access
                        @endif
                    </span>
                </td>
                <td class="amount">{{ $invoice->formattedSubtotal() }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        @if($invoice->tax_amount > 0)
        <div class="totals-row">
            <span>Subtotal</span>
            <span>{{ $invoice->formattedSubtotal() }}</span>
        </div>
        <div class="totals-row">
            <span>Tax ({{ $invoice->tax_rate }}%)</span>
            <span>{{ $invoice->formattedTax() }}</span>
        </div>
        @endif
        <div class="totals-row total">
            <span>Total</span>
            <span>{{ $invoice->formattedTotal() }}</span>
        </div>
    </div>

    <div class="footer">
        <p>Thank you for your business. This invoice was generated automatically by Exospace Gallery.</p>
        <p>For billing questions, contact support@exospace.gallery</p>
    </div>
</body>
</html>

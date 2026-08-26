<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; padding: 20px; margin: 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        h2 { color: #1f2937; margin-bottom: 20px; }
        p { color: #4b5563; line-height: 1.6; margin-bottom: 15px; }
        table.stats { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table.stats td, table.stats th { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        table.stats th { color: #6b7280; font-weight: 600; }
        table.stats td { color: #1f2937; }
        table.stats td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .warn { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; padding: 14px 16px; margin: 20px 0; color: #92400e; }
        .note { background: #f9fafb; padding: 14px 16px; border-radius: 6px; margin: 20px 0; font-size: 13px; color: #6b7280; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Weekly billing digest</h2>

        <p>Money events for {{ $window['from'] }} → {{ $window['to']}}. The attached CSV uses the same
            columns as the on-demand export on Master Control → Billing Review, so it drops straight into
            your 2Checkout statement match.</p>

        <table class="stats">
            <tr><th>Metric (trailing 7 days)</th><th style="text-align:right;">Value</th></tr>
            <tr><td>Completed sales</td><td class="num">{{ number_format($summary['completed']) }}</td></tr>
            <tr><td>Revenue (completed)</td><td class="num">{{ number_format($summary['revenue'], 2) }}</td></tr>
            <tr><td>Full refunds</td><td class="num">{{ number_format($summary['refunded']) }}</td></tr>
            <tr><td>Partial refunds</td><td class="num">{{ number_format($summary['partial_refund']) }}</td></tr>
            <tr><td>Chargebacks</td><td class="num">{{ number_format($summary['chargeback']) }}</td></tr>
            <tr><td>Manual adjustments</td><td class="num">{{ number_format($summary['manual']) }}</td></tr>
        </table>

        @if($csv['count'] > 0)
            <p><strong>Attachment:</strong> {{ $csv['filename'] }} — {{ $csv['count'] }} money event row{{ $csv['count'] === 1 ? '' : 's' }}
                (refunds, partial refunds, chargebacks).</p>
        @else
            <p><strong>No money events</strong> (refunds / partial refunds / chargebacks) occurred this week — no CSV is attached.</p>
        @endif

        @if($summary['failed_webhooks'] > 0)
            <div class="warn">
                <strong>{{ $summary['failed_webhooks'] }} webhook{{ $summary['failed_webhooks'] === 1 ? '' : 's' }} currently marked failed</strong>
                in the ledger — money events may not have been applied. Review and replay at Master Control → Billing Review.
            </div>
        @endif

        <div class="note">
            This digest is an operational notification to the configured billing-export address
            (BILLING_EXPORT_EMAIL), not a marketing email. Every send is audit-logged as
            <code>billing.exported</code> (actor: system). The CSV contains customer billing data —
            handle per your data-retention policy.
        </div>

        <div class="footer">
            Sent by Exospace · Weekly (Mondays 07:00) · Manage recipients on Master Control → Billing Review (or via the BILLING_EXPORT_EMAIL env var before any UI-managed recipient is added)
        </div>
    </div>
</body>
</html>

Weekly billing digest ({{ $window['from'] }} → {{ $window['to'] }})

Completed sales: {{ number_format($summary['completed']) }}
Revenue (completed): {{ number_format($summary['revenue'], 2) }}
Full refunds: {{ number_format($summary['refunded']) }}
Partial refunds: {{ number_format($summary['partial_refund']) }}
Chargebacks: {{ number_format($summary['chargeback']) }}
Manual adjustments: {{ number_format($summary['manual']) }}

@if($csv['count'] > 0)
Attachment: {{ $csv['filename'] }} — {{ $csv['count'] }} money event row{{ $csv['count'] === 1 ? '' : 's' }} (same columns as the Billing Review on-demand export).
@else
No money events (refunds / partial refunds / chargebacks) occurred this week — no CSV is attached.
@endif

@if($summary['failed_webhooks'] > 0)
WARNING: {{ $summary['failed_webhooks'] }} webhook(s) currently marked failed in the ledger — money events may not have been applied. Review and replay at Master Control → Billing Review.
@endif

This digest is an operational notification to the configured billing-export address
(BILLING_EXPORT_EMAIL), not a marketing email. Every send is audit-logged as billing.exported
(actor: system). The CSV contains customer billing data — handle per your data-retention policy.

Sent by Exospace · Weekly (Mondays 07:00) · Manage recipients on Master Control → Billing Review (or via the BILLING_EXPORT_EMAIL env var before any UI-managed recipient is added)

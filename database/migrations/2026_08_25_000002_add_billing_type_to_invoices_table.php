<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-1 FIX (billing correctness): add invoices.billing_type.
 *
 * Why: the invoice PDF previously detected "subscription vs one-time" by
 * reading `$invoice->transaction->subscription_id` — a column that does
 * NOT exist on the transactions table (it lives on users). The expression
 * was therefore always null and EVERY invoice, including monthly
 * subscription renewals, was labelled "One-time purchase — Lifetime
 * access". For a business sending invoices to galleries for accounting,
 * that is a real financial-documentation error.
 *
 * billing_type is set at invoice-creation time (webhook context knows
 * whether 2Checkout sent recurring_order_id) and is immutable afterwards —
 * invoices are financial records and must describe the purchase as it was.
 *
 * Values: 'subscription' | 'one_time' | null (legacy rows — the PDF falls
 * back to a neutral label rather than a wrong one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('billing_type', 20)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('invoices')) {
            return;
        }
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};

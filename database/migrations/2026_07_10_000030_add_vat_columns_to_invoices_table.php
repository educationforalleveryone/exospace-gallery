<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iteration-008 (audit 2CO-7 + O-10): Add VAT invoice compliance columns.
 *
 * The previous invoices table stored tax_amount + tax_rate but had no way
 * to record the customer's VAT number, the supplier's VAT number, or the
 * reverse-charge flag — all required for a VAT-compliant invoice in the
 * EU (VAT MOSS), UK (HMRC), Germany (GoBD), Italy (SDI), and Australia.
 *
 * New columns:
 *   - customer_vat_number: the customer's VAT/GST/Tax ID at time of sale
 *     (may be null for B2C customers)
 *   - supplier_vat_number: the supplier's VAT number at time of sale
 *     (snapshot — if our VAT number changes, historical invoices keep
 *     the one that applied at the time of sale)
 *   - tax_country_code: 2-letter ISO country code that the tax was
 *     charged under (e.g. 'DE' for Germany VAT, 'GB' for UK VAT)
 *   - reverse_charge: boolean — true if reverse-charge accounting applies
 *     (B2B intra-EU, B2B EU→UK). The invoice PDF shows "Reverse charge"
 *     notation instead of a tax amount.
 *
 * All new columns are nullable so the migration is safe on existing rows
 * (existing invoices will have NULL customer_vat_number, NULL
 * supplier_vat_number, NULL tax_country_code, false reverse_charge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Customer's VAT/GST/Tax ID at time of sale. NULL for B2C.
            $table->string('customer_vat_number', 40)->nullable()->after('billing_address');

            // Supplier's VAT number at time of sale (snapshot).
            $table->string('supplier_vat_number', 40)->nullable()->after('customer_vat_number');

            // 2-letter ISO country code that the tax was charged under.
            $table->string('tax_country_code', 2)->nullable()->after('supplier_vat_number');

            // Reverse-charge flag (B2B intra-EU, B2B EU→UK, etc.).
            $table->boolean('reverse_charge')->default(false)->after('tax_country_code');
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
            $table->dropColumn([
                'customer_vat_number',
                'supplier_vat_number',
                'tax_country_code',
                'reverse_charge',
            ]);
        });
    }
};

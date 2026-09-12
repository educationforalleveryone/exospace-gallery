<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

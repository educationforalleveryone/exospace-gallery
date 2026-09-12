<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            return;
        }

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable();

            $table->string('invoice_number')->unique();

            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0); // e.g. 20.00 for 20% VAT
            $table->string('currency', 10)->default('USD');
            $table->string('plan');

            // Customer details at time of purchase (for B2B invoicing).
            $table->string('customer_name')->nullable();
            $table->string('customer_email');
            $table->text('billing_address')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('transaction_id');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

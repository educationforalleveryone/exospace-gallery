<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('invoice_id')->unique();
            $table->string('sale_id')->nullable();
            $table->string('product_id')->nullable();
            $table->string('plan');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('status')->default('completed'); // completed | refunded
            $table->timestamps();

            $table->index('user_id');
            $table->index('customer_email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
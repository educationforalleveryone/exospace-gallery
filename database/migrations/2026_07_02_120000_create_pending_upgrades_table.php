<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->string('plan', 20);          // pro | studio
            $table->string('product_id', 100);   // 2Checkout product ID
            $table->string('status', 20)->default('pending'); // pending | converted | expired
            $table->foreignId('transaction_id')->nullable();  // set when IPN arrives
            $table->timestamp('expires_at')->nullable();      // 7 days from creation
            $table->timestamps();

            $table->index('user_id');
            $table->index('token');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_upgrades');
    }
};

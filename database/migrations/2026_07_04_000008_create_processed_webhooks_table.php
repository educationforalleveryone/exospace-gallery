<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 100);
            $table->string('message_type', 50);
            $table->string('invoice_id', 100)->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->unique(['message_id', 'message_type']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};

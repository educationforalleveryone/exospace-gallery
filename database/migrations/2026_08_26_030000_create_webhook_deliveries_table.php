<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('webhook_deliveries')) {
            return;
        }

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('event_type', 100);
            $table->string('target_url', 500);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('attempt_count');
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('delivered_at')->useCurrent();

            $table->foreign('subscription_id')
                ->references('id')
                ->on('webhook_subscriptions')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['subscription_id', 'delivered_at']);
            $table->index(['event_type', 'delivered_at']);
            // Retention cleanup DELETE WHERE delivered_at < ...
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_deliveries')) {
            Schema::drop('webhook_deliveries');
        }
    }
};

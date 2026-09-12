<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('webhook_subscriptions')) {
            return;
        }

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);
            $table->string('target_url', 500);
            $table->string('secret', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('added_by')->nullable();

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['event_type', 'target_url']);
            $table->index(['event_type', 'is_active']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_subscriptions')) {
            Schema::drop('webhook_subscriptions');
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Notification category: billing, subscription, system, gallery, dunning, etc.
            $table->string('type');

            // Display fields
            $table->string('title');
            $table->text('body')->nullable();

            // Optional action link
            $table->string('action_url')->nullable();
            $table->string('action_label')->nullable();

            // Read tracking — null = unread, timestamp = read
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Indexes: user needs to query their unread notifications fast
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

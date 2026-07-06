<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-12: In-app notifications table.
 *
 * Stores per-user notifications shown in the notification bell dropdown.
 * Each notification has:
 *   - type: the notification category (billing, subscription, system, gallery, etc.)
 *   - title: short headline
 *   - body: longer description (optional)
 *   - action_url: link to the relevant page (optional)
 *   - action_label: text for the link (optional)
 *   - read_at: null = unread, timestamp = when the user dismissed/read it
 *
 * Notifications are created by NotificationService::create() and displayed
 * in the navigation bell dropdown. Users can mark individual notifications
 * as read or mark all as read.
 *
 * Unlike Laravel's built-in Notification system (which uses a polymorphic
 * notifiable + notification_type pattern), this is a simpler flat table —
 * easier to query, index, and understand. The built-in system is overkill
 * for Exospace's needs (no database notifications, no mail/Slack channels,
 * just a simple in-app bell).
 */
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

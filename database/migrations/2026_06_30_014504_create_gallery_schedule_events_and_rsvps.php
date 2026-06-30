<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates two new tables for the event calendar feature:
 *
 *   - gallery_schedule_events: actual calendar events (opening reception,
 *     artist talk, walkthrough, etc.) — NOT to be confused with
 *     analytics_events (which was renamed from gallery_events in
 *     migration 2026_06_22_000001)
 *
 *   - event_rsvps: email captures from visitors who want to attend
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_schedule_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('event');
            // Allowed types: opening, artist_talk, walkthrough, workshop, closing, event

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 50)->default('UTC');

            // Optional location (for hybrid physical + virtual events)
            $table->string('location_name', 255)->nullable();
            $table->string('location_url', 500)->nullable(); // Zoom/Meet/stream link

            // Capacity: null = unlimited
            $table->unsignedInteger('capacity')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['gallery_id', 'starts_at']);
            $table->index('starts_at');
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_event_id')
                  ->constrained('gallery_schedule_events')
                  ->onDelete('cascade');

            $table->string('name', 100);
            $table->string('email', 255);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('confirmed_at')->useCurrent();

            $table->timestamps();

            // One RSVP per email per event
            $table->unique(['schedule_event_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('gallery_schedule_events');
    }
};

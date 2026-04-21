<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->foreignId('image_id')->nullable()->constrained('gallery_images')->onDelete('set null');

            // Event type: 'view' (gallery open) | 'focus' (artwork inspected) | 'tour_start' | 'tour_complete'
            $table->string('event', 32)->index();

            // Session token — random UUID per visitor session (no auth required)
            $table->string('session_token', 64)->index();

            // Time on page / dwell in seconds (null until session ends)
            $table->unsignedSmallInteger('dwell_seconds')->nullable();

            // Referrer domain (e.g. "instagram.com", "direct", "google.com")
            $table->string('referrer', 255)->nullable();

            // Country code from IP (optional, can be added later via GeoIP)
            $table->string('country', 2)->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['gallery_id', 'event']);
            $table->index(['gallery_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_events');
    }
};
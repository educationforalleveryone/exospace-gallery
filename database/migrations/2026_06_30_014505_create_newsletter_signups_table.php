<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `newsletter_signups` table.
 *
 * Visitors sign up in the entrance curtain of a gallery (before entering).
 * The signup is attributed to the gallery so the curator can see their
 * audience in analytics. Exospace platform owners can also see all
 * signups across all galleries (for cross-promotion, future monetization).
 *
 * Each signup captures: email, gallery_id, optional name, IP (for spam
 * analysis), and timestamps. A unique constraint on (gallery_id, email)
 * prevents duplicate signups for the same gallery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->string('email', 255);
            $table->string('name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->timestamp('signed_up_at')->useCurrent();
            $table->timestamps();

            // One signup per email per gallery
            $table->unique(['gallery_id', 'email']);
            $table->index('email');
            $table->index('signed_up_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_signups');
    }
};

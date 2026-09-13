<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries')) {
            return;
        }

        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('venue_template_id')->nullable()->constrained()->onDelete('set null');

            // Basic info
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('wall_texture', 20)->default('white');
            $table->string('frame_style', 20)->default('modern');
            $table->string('lighting_preset', 20)->default('bright');
            $table->string('floor_material', 20)->default('wood');
            $table->string('room_layout', 20)->default('square');

            // Media paths (disk-relative paths)
            $table->string('audio_path', 500)->nullable();
            $table->string('custom_logo_path', 500)->nullable();
            $table->string('curtain_logo_path', 500)->nullable();
            $table->string('curtain_bg_color', 20)->nullable();

            // Access control
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('view_count')->default(0);

            // Scheduling
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            // Custom domain (Studio plan)
            $table->string('custom_domain')->nullable()->unique();
            $table->string('custom_domain_verification_token', 64)->nullable();
            $table->timestamp('custom_domain_verified_at')->nullable();

            // Featured exhibitions (super-admin curated)
            $table->boolean('is_featured')->default(false);

            // Visual overrides (Live Preview — per-gallery tweaks)
            $table->json('visual_overrides')->nullable();

            $table->timestamps();

            $table->softDeletes();

            // Indexes
            $table->index('user_id');
            $table->index('team_id');
            $table->index('venue_template_id');
            $table->index('is_active');
            $table->index('is_featured');
            $table->index('custom_domain_verified_at');
            $table->index('opens_at');
            $table->index('closes_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('gallery_schedule_events');
        Schema::dropIfExists('galleries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Gallery Configuration
            $table->enum('wall_texture', ['white', 'concrete', 'brick', 'wood'])->default('white');
            $table->enum('frame_style', ['modern', 'classic', 'minimal'])->default('modern');
            $table->enum('lighting_preset', ['bright', 'moody', 'dramatic'])->default('bright');
            $table->enum('floor_material', ['wood', 'marble', 'concrete'])->default('wood');
            
            // Metadata
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();
            
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galleries');
    }
};
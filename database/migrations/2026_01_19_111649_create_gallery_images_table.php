<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            
            // Image Data
            $table->string('filename');
            $table->string('original_name');
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size'); // in bytes
            
            // Image Metadata
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->enum('orientation', ['portrait', 'landscape', 'square']);
            
            // Gallery Positioning
            $table->unsignedInteger('position_order')->default(0);
            $table->enum('wall_position', ['front', 'left', 'right', 'back'])->nullable();
            
            // Optional Fields
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            
            $table->timestamps();
            
            $table->index('position_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
    }
};
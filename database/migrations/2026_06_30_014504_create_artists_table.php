<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('portrait_path', 500)->nullable(); // uploaded portrait photo
            $table->string('website', 500)->nullable();
            $table->string('instagram', 255)->nullable(); // handle without @
            $table->string('twitter', 255)->nullable();   // handle without @
            $table->string('email', 255)->nullable();     // public contact email (shown on profile)
            $table->string('location', 255)->nullable();  // e.g. "Berlin, Germany"

            $table->foreignId('created_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('slug');
            $table->index('created_by');
        });

        // Add artist_id to gallery_images
        Schema::table('gallery_images', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()
                  ->after('gallery_id')
                  ->constrained('artists')->nullOnDelete();
            $table->index('artist_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('gallery_images')) {
            $columns = Schema::getColumnListing('gallery_images');

            if (in_array('artist_id', $columns, true) && count($columns) > 1) {
                try {
                    Schema::table('gallery_images', function (Blueprint $table) {
                        $table->dropForeign(['artist_id']);
                    });
                } catch (\Throwable) {
                    // FK absent (consolidated-migration path).
                }
                try {
                    Schema::table('gallery_images', function (Blueprint $table) {
                        $table->dropIndex(['artist_id']);
                    });
                } catch (\Throwable) {
                    // Index absent (consolidated-migration path).
                }
                Schema::table('gallery_images', function (Blueprint $table) {
                    $table->dropColumn('artist_id');
                });
            } else {
                Schema::dropIfExists('gallery_images');
            }
        }

        Schema::dropIfExists('artists');
    }
};

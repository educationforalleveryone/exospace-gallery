<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `artists` table and adds an `artist_id` FK to `gallery_images`.
 *
 * Design choice: artists are METADATA, not users (Option A).
 * Curators create and own artist profiles. Artists have no login.
 *
 * If we later want artists to have their own accounts (Option B), we
 * add a nullable `user_id` FK to this table and an invitation flow.
 * The data model below is forward-compatible with that upgrade.
 */
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

            // Ownership: who created this artist profile?
            // The curator (User) who first added this artist.
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
        // ITERATION-1 FIX (portable rollback): by the time this migration's
        // down() runs on a fresh-install rollback, the consolidated
        // galleries migration has ALREADY dropped gallery_images (it runs
        // later in the batch, so it rolls back FIRST). Every gallery_images
        // ALTER below is therefore guarded; when the table survives with
        // only artist_id left, drop the table (SQLite compiles an empty
        // __temp__ rebuild when the drop set covers every column).
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
                // Only artist_id remains (or the column is already gone) —
                // the table is fully wound down.
                Schema::dropIfExists('gallery_images');
            }
        }

        Schema::dropIfExists('artists');
    }
};

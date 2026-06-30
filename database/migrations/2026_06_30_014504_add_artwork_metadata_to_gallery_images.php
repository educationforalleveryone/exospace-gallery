<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-artwork metadata columns to `gallery_images`.
 *
 * These fields power the future "Inquire" button (Round 5 candidate) and
 * the public artist profile pages (which list an artist's works with
 * full metadata). They're also displayed in the focus-mode info panel
 * when a visitor inspects an artwork.
 *
 * All columns are nullable — existing artworks (which have only title +
 * description) continue to work unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            // Sales metadata
            $table->decimal('price', 10, 2)->nullable()->after('description');
            $table->string('currency', 3)->default('USD')->after('price');
            $table->boolean('for_sale')->default(false)->after('currency');

            // Artwork attributes
            $table->string('medium', 255)->nullable()->after('for_sale');
            $table->year('year')->nullable()->after('medium');
            $table->string('dimensions', 100)->nullable()->after('year'); // e.g. "120 × 80 cm"
            $table->unsignedInteger('edition_size')->nullable()->after('dimensions');
            $table->string('edition_number', 50)->nullable()->after('edition_size'); // e.g. "3 of 25"

            // External link (artist's own page, ArtStation, etc.)
            $table->string('external_url', 500)->nullable()->after('edition_number');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_images', function (Blueprint $table) {
            $columns = ['price', 'currency', 'for_sale', 'medium', 'year',
                        'dimensions', 'edition_size', 'edition_number', 'external_url'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('gallery_images', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

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
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('gallery_images')) {
            return;
        }
        // ITERATION-1 FIX: dropping ALL of these columns at once can empty
        // the column set on consolidated-schema installs (the table was
        // created WITH these columns by the consolidated migration that
        // already rolled back... it hasn't — but when every listed column
        // is absent, Blueprint compiles `create table __temp__ ()` — a
        // syntax error). Compute the surviving set and skip when empty.
        $drop = array_values(array_filter(
            ['price', 'currency', 'for_sale', 'medium', 'year',
             'dimensions', 'edition_size', 'edition_number', 'external_url'],
            fn ($col) => Schema::hasColumn('gallery_images', $col),
        ));
        if ($drop === []) {
            return;
        }
        // ITERATION-1 FIX (empty temp-table guard): SQLite rebuilds the
        // table via __temp__ on column drop. If the drop set covers EVERY
        // remaining column, the rebuild compiles empty parentheses — a
        // syntax error. Drop the whole table instead in that case.
        $remaining = collect(Schema::getColumnListing('gallery_images'))
            ->diff($drop)
            ->values()
            ->all();
        if ($remaining === []) {
            Schema::dropIfExists('gallery_images');
            return;
        }
        Schema::table('gallery_images', function (Blueprint $table) use ($drop) {
            foreach ($drop as $col) {
                $table->dropColumn($col);
            }
        });
    }
};

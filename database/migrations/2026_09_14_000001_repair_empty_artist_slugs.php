<?php

use App\Models\Artist;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Artist slugs are unique public identifiers resolved by /artist/{slug}.
    // Rows created before server-side slug generation can exist with an
    // empty value (names that slugify to nothing), leaving the profile
    // without a resolvable URL and blocking later inserts on the unique
    // index.
    public function up(): void
    {
        Artist::query()
            ->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))
            ->get()
            ->each(fn (Artist $artist) => $artist->save());
    }

    public function down(): void
    {
        //
    }
};

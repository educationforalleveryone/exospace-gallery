<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert galleries.wall_texture, frame_style, floor_material columns from
 * MySQL ENUM to VARCHAR(20).
 *
 * WHY
 * ---
 * The v3.0 refactor added new wall/floor/frame materials (plaster, marble,
 * velvet, terrazzo, grass, sand, gold, silver, bronze, black). The Laravel
 * validation rules in GalleryController accept these new values, but the
 * underlying MySQL columns were defined as ENUMs with the OLD value lists
 * (see the original 2026_01_19_111649_create_galleries_table migration).
 *
 * Symptom: choosing Sculpture Garden (which sets floor_material='grass')
 * and clicking "Update Settings" throws a 500 with:
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'floor_material'
 *
 * The same error would occur for any gallery using the new materials.
 *
 * FIX
 * ---
 * Convert the three columns to VARCHAR(20). This:
 *   - Preserves all existing data (ENUM values are valid VARCHAR values)
 *   - Allows any future material value the Laravel validation accepts
 *   - Removes the need for a migration every time we add a new material
 *   - Makes Laravel validation the single source of truth for allowed values
 *
 * SQLite COMPATIBILITY (task C15)
 * -------------------------------
 * The previous version of this migration used raw `ALTER TABLE ...
 * MODIFY COLUMN` which is MySQL-only syntax. SQLite does not support
 * MODIFY COLUMN — the test suite (phpunit.xml sets DB_CONNECTION=sqlite)
 * could not bootstrap, which is why tests/Feature/ contained only stock
 * Breeze stubs and zero coverage of actual SaaS logic.
 *
 * The fix: guard each statement with a driver check. On MySQL we use
 * the cheap `MODIFY COLUMN` (preserves column position + data). On
 * SQLite we use Laravel's schema builder `change()` which rebuilds the
 * table — slower but correct, and only ever runs in the test suite.
 *
 * We leave lighting_preset and room_layout as ENUMs because:
 *   - They have no new values in v3.0
 *   - The ENUM constraint is harmless for them
 *   - Changing them would be unnecessary churn
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite path — used by the test suite. Schema builder's
            // change() rebuilds the table behind the scenes.
            Schema::table('galleries', function ($table) {
                $table->string('wall_texture', 20)->default('white')->change();
                $table->string('frame_style', 20)->default('modern')->change();
                $table->string('floor_material', 20)->default('wood')->change();
            });
        } else {
            // MySQL / MariaDB path — preserves column position + data.
            // MODIFY COLUMN is the cheap O(1) operation here.
            DB::statement("ALTER TABLE galleries MODIFY COLUMN wall_texture VARCHAR(20) NOT NULL DEFAULT 'white'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN frame_style VARCHAR(20) NOT NULL DEFAULT 'modern'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN floor_material VARCHAR(20) NOT NULL DEFAULT 'wood'");
        }
    }

    public function down(): void
    {
        // Revert to the original ENUM definitions.
        // WARNING: any rows with values outside the original ENUM lists will
        // fail this downgrade. If you have such rows, delete or fix them first.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite can't represent ENUM — best we can do is keep VARCHAR(20).
            // The down() migration is only used in tests where preserving
            // the exact ENUM constraint doesn't matter.
            // No-op.
        } else {
            DB::statement("ALTER TABLE galleries MODIFY COLUMN wall_texture ENUM('white','concrete','brick','wood') NOT NULL DEFAULT 'white'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN frame_style ENUM('modern','classic','minimal') NOT NULL DEFAULT 'modern'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN floor_material ENUM('wood','marble','concrete') NOT NULL DEFAULT 'wood'");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('galleries', function ($table) {
                $table->string('wall_texture', 20)->default('white')->change();
                $table->string('frame_style', 20)->default('modern')->change();
                $table->string('floor_material', 20)->default('wood')->change();
            });
        } else {
            DB::statement("ALTER TABLE galleries MODIFY COLUMN wall_texture VARCHAR(20) NOT NULL DEFAULT 'white'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN frame_style VARCHAR(20) NOT NULL DEFAULT 'modern'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN floor_material VARCHAR(20) NOT NULL DEFAULT 'wood'");
        }
    }

    public function down(): void
    {
        // The consolidated users migration (2026_07_02_160000) already
        // dropped galleries earlier in the rollback, so the table can be
        // gone by the time this runs; the re-migrate re-creates it from the
        // base migration with the original ENUM columns.
        if (! Schema::hasTable('galleries')
            || ! Schema::hasColumn('galleries', 'wall_texture')
            || ! Schema::hasColumn('galleries', 'frame_style')
            || ! Schema::hasColumn('galleries', 'floor_material')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
        } else {
            DB::statement("ALTER TABLE galleries MODIFY COLUMN wall_texture ENUM('white','concrete','brick','wood') NOT NULL DEFAULT 'white'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN frame_style ENUM('modern','classic','minimal') NOT NULL DEFAULT 'modern'");
            DB::statement("ALTER TABLE galleries MODIFY COLUMN floor_material ENUM('wood','marble','concrete') NOT NULL DEFAULT 'wood'");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('galleries')
            || Schema::hasColumn('galleries', 'visual_overrides')) {
            return;
        }
        Schema::table('galleries', function (Blueprint $table) {
            $table->json('visual_overrides')->nullable()->after('room_layout');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('galleries')
            || ! Schema::hasColumn('galleries', 'visual_overrides')) {
            return;
        }
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('visual_overrides');
        });
    }
};

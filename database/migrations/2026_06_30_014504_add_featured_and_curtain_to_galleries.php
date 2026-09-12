<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('view_count')->index();
            $table->string('curtain_logo_path', 500)->nullable()->after('custom_logo_path');
            $table->string('curtain_bg_color', 20)->nullable()->after('curtain_logo_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('galleries')) {
            return;
        }
        $drop = array_values(array_filter(
            ['is_featured', 'curtain_logo_path', 'curtain_bg_color'],
            fn ($col) => Schema::hasColumn('galleries', $col),
        ));
        if ($drop === []) {
            return;
        }
        $remaining = collect(Schema::getColumnListing('galleries'))
            ->diff($drop)->values()->all();
        if ($remaining === []) {
            Schema::dropIfExists('galleries');
            return;
        }
        Schema::table('galleries', function (Blueprint $table) use ($drop) {
            if (in_array('is_featured', $drop, true)) {
                $table->dropIndex(['is_featured']);
                $table->dropColumn('is_featured');
            }
            if (in_array('curtain_logo_path', $drop, true)) {
                $table->dropColumn('curtain_logo_path');
            }
            if (in_array('curtain_bg_color', $drop, true)) {
                $table->dropColumn('curtain_bg_color');
            }
        });
    }
};

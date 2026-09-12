<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'is_active')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->boolean('is_active')->default(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'is_active')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->change();
            });
        }
    }
};

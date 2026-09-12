<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries') && ! Schema::hasColumn('galleries', 'published_at')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->timestamp('published_at')->nullable()->after('is_active');
            });
        }

        if (Schema::hasColumn('galleries', 'published_at')) {
            DB::table('galleries')
                ->where('is_active', true)
                ->whereNull('published_at')
                ->update(['published_at' => DB::raw('created_at')]);
        }

        // Index for the discover sort (orderByDesc('published_at')).
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'published_at')
            && ! Schema::hasIndex('galleries', 'galleries_published_at_index')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->index('published_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'published_at')) {
            if (Schema::hasIndex('galleries', 'galleries_published_at_index')) {
                Schema::table('galleries', function (Blueprint $table) {
                    $table->dropIndex('galleries_published_at_index');
                });
            }

            Schema::table('galleries', function (Blueprint $table) {
                $table->dropColumn('published_at');
            });
        }
    }
};

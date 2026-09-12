<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('custom_domain', 255)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('galleries')
            || ! Schema::hasColumn('galleries', 'custom_domain')) {
            return;
        }
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('custom_domain');
        });
    }
};

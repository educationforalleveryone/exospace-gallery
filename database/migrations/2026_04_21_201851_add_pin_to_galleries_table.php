<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            // 4-digit PIN stored as bcrypt hash (null = no PIN required)
            $table->string('pin_hash')->nullable()->after('room_layout');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('pin_hash');
        });
    }
};
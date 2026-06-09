<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('venue_template_id')->nullable()->constrained('venue_templates')->nullOnDelete()->after('room_layout');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\VenueTemplate::class);
            $table->dropColumn('venue_template_id');
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gallery_events') && !Schema::hasTable('analytics_events')) {
            Schema::rename('gallery_events', 'analytics_events');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('analytics_events') && !Schema::hasTable('gallery_events')) {
            Schema::rename('analytics_events', 'gallery_events');
        }
    }
};

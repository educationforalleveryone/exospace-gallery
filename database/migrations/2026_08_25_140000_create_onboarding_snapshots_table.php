<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('window_days');
            $table->unsignedInteger('registered')->default(0);
            $table->unsignedInteger('created_gallery')->default(0);
            $table->unsignedInteger('uploaded_image')->default(0);
            $table->unsignedInteger('published')->default(0);
            $table->unsignedInteger('got_views')->default(0);
            $table->decimal('ttfg_min', 8, 1)->nullable();
            $table->decimal('ttfg_avg', 8, 1)->nullable();
            $table->decimal('ttfg_max', 8, 1)->nullable();
            $table->decimal('ttfe_min', 8, 1)->nullable();
            $table->decimal('ttfe_avg', 8, 1)->nullable();
            $table->decimal('ttfe_max', 8, 1)->nullable();
            $table->timestamp('captured_at')->nullable();

            $table->unique(['window_days', 'captured_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('onboarding_snapshots')) {
            Schema::drop('onboarding_snapshots');
        }
    }
};

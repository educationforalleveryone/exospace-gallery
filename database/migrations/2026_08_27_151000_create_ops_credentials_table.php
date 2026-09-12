<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_credentials', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->timestamp('last_rotated_at')->nullable();
            $table->foreignId('rotated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('notes', 250)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_credentials');
    }
};

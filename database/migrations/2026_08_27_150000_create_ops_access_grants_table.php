<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_access_grants', function (Blueprint $table) {
            $table->id();
            // Who receives access.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // 'viewer' (read-only). Fail-closed for anything else.
            $table->string('level', 20)->default('viewer');
            $table->foreignId('granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_access_grants');
    }
};

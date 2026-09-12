<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_templates', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('published_at');
            $table->index('archived_at');
        });

        Schema::create('venue_template_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_template_id')
                ->constrained('venue_templates')
                ->cascadeOnDelete();
            $table->string('label')->nullable();      // "before save #12", "before restore"
            $table->json('config');                    // restorable content payload
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();                      // snapshot survives user deletion
            $table->timestamp('created_at')->nullable();

            $table->index(['venue_template_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_template_snapshots');

        Schema::table('venue_templates', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};

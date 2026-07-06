<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-19: User feedback table.
 *
 * Stores feedback submitted via the in-app feedback widget (floating
 * "Feedback" button on admin pages). Each entry has:
 *   - user_id: the user who submitted (nullable for logged-out users on
 *     public pages — but the widget is currently admin-only)
 *   - category: bug, feature_request, praise, other
 *   - message: the feedback text
 *   - page_url: the URL the user was on when they submitted (context)
 *   - user_agent: browser info (for debugging browser-specific issues)
 *   - status: new, reviewed, resolved (for admin triage)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('category')->default('other'); // bug, feature_request, praise, other
            $table->text('message');
            $table->string('page_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('new'); // new, reviewed, resolved
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback');
    }
};

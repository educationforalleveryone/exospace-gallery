<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-5 FIX (Iter-006): GDPR data-subject-request workflow.
 *
 * Creates a `gdpr_deletion_requests` table to track right-to-be-forgotten
 * requests. This provides:
 *   - Proof of compliance for auditors (when was the request made, when was
 *     it completed, who processed it)
 *   - A 30-day grace period before permanent deletion (common SaaS pattern)
 *   - An admin UI to view/manage pending requests
 *
 * When a user requests deletion:
 *   1. A gdpr_deletion_requests row is created (status=pending).
 *   2. The user's account is soft-deleted (or deactivated) but data is retained.
 *   3. After 30 days (or admin approval), UserDeletionService::deleteUser()
 *      is called to permanently delete the data.
 *   4. The request status is updated to 'completed'.
 *
 * If the user logs in within the 30-day window, the request is cancelled
 * (status=cancelled) and the account is reactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gdpr_deletion_requests')) {
            return;
        }

        Schema::create('gdpr_deletion_requests', function (Blueprint $table) {
            $table->id();
            // The user who requested deletion. Nullable because the user may
            // be deleted before the request is completed (the request row
            // survives as the audit trail).
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            // The email of the user (preserved even after user_id is nulled)
            $table->string('email');
            // Status: pending, processing, completed, cancelled
            $table->string('status', 20)->default('pending');
            // The IP address of the requester (for audit)
            $table->string('requester_ip', 45)->nullable();
            // The admin who approved/processed the request (if applicable)
            $table->foreignId('admin_actor_id')->nullable()->constrained('users')->onDelete('set null');
            // When the request was made
            $table->timestamp('requested_at')->useCurrent();
            // When the 30-day grace period expires (auto-delete scheduled)
            $table->timestamp('scheduled_deletion_at')->nullable();
            // When the deletion was actually performed
            $table->timestamp('completed_at')->nullable();
            // Optional reason provided by the user
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
            $table->index('scheduled_deletion_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_deletion_requests');
    }
};

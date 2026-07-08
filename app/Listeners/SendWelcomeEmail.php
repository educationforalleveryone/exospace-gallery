<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send the WelcomeEmail when a new user registers.
 *
 * ITERATION-005 FIX (audit C-4): Removed session() call from queued job.
 *
 * Previously, the handle() method called session('invitation_accepted_at')
 * to check if the registration was via a team invitation. But this listener
 * implements ShouldQueue — handle() runs on the queue worker, NOT in the
 * HTTP request. Queue workers have no session. session() returns null,
 * the && short-circuits, and the defensive check NEVER fires.
 *
 * If RegisteredUserController ever stopped suppressing the Registered event
 * for invited users, invited users would get a redundant welcome email on
 * top of their team-invitation email — exactly the bug the listener was
 * supposed to prevent.
 *
 * FIX: Check the user's email_verified_at timestamp instead. Invitation-
 * accepted registrations are auto-verified (email_verified_at = now() at
 * registration time, set by RegisteredUserController). Normal registrations
 * have email_verified_at = null until the user clicks the verification link.
 *
 * If the user is already verified at the time the welcome email would be
 * sent, it's likely an invitation-accepted registration → skip the welcome
 * email (the team-invitation email already served as their welcome).
 *
 * (Task H03 / audit H4) — previously this listener didn't exist. The
 * WelcomeEmail mailable was committed but never wired up.
 *
 * Implements ShouldQueue so the email send doesn't block the registration
 * request.
 */
class SendWelcomeEmail implements ShouldQueue
{
    public function __construct() {}

    /**
     * Handle the event.
     *
     * C-4 FIX: No longer uses session() — checks email_verified_at instead.
     */
    public function handle(Registered $event): void
    {
        $user = $event->user;

        // C-4 FIX: Check email_verified_at instead of session().
        //
        // Invitation-accepted registrations are auto-verified by
        // RegisteredUserController (it sets email_verified_at = now() before
        // firing the Registered event). Normal registrations have
        // email_verified_at = null (the user must click the verification link).
        //
        // If the user is already verified, skip the welcome email — the
        // team-invitation email already served as their welcome.
        //
        // This check works on the queue worker (no session needed) and
        // correctly identifies invitation-accepted registrations.
        if ($user->hasVerifiedEmail()) {
            Log::info('SendWelcomeEmail: skipping for already-verified user (likely invitation-accepted)', [
                'user_id'         => $user->id,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ]);
            return;
        }

        Log::info('SendWelcomeEmail: queueing welcome email', [
            'user_id' => $user->id,
        ]);

        Mail::to($user->email)->send(new WelcomeEmail($user));
    }
}

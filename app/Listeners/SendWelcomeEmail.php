<?php

namespace App\Listeners;

use App\Mail\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send the WelcomeEmail when a new user registers.
 *
 * (Task H03 / audit H4) — previously this listener didn't exist. The
 * WelcomeEmail mailable was committed but never wired up. New users got
 * only the email-verification notification from Laravel's built-in
 * SendEmailVerificationNotification listener.
 *
 * This listener is auto-discovered by Laravel 11+ (no manual registration
 * in EventServiceProvider needed). It runs AFTER the verification email
 * listener so the user receives the verification email first, then the
 * welcome email a moment later.
 *
 * Implements ShouldQueue so the email send doesn't block the registration
 * request. With QUEUE_CONNECTION=redis (production per DEPLOYMENT.md),
 * the email is sent by the queue worker.
 */
class SendWelcomeEmail implements ShouldQueue
{
    public function __construct() {}

    /**
     * Handle the event.
     *
     * Skipped for invitation-accepted registrations because those users
     * are auto-verified and the invitation email already served as their
     * welcome. RegisteredUserController suppresses the Registered event
     * for invited users, so this listener won't fire for them — but this
     * check is defensive in case that suppression is ever removed.
     */
    public function handle(Registered $event): void
    {
        $user = $event->user;

        // Defensive: don't send welcome email to users who registered
        // via team invitation (they got the team-invitation email instead).
        // RegisteredUserController::store currently suppresses the event
        // for invited users, but if that ever changes this check catches
        // it.
        if (session('invitation_accepted_at') && now()->diffInSeconds(session('invitation_accepted_at')) < 60) {
            Log::info('SendWelcomeEmail: skipping for invitation-accepted registration', [
                'user_id' => $user->id,
            ]);
            return;
        }

        Log::info('SendWelcomeEmail: queueing welcome email', [
            'user_id' => $user->id,
        ]);

        Mail::to($user->email)->send(new WelcomeEmail($user));
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\WelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
{
    public function __construct() {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

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

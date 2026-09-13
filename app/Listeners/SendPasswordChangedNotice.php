<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\PasswordChangedNoticeMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendPasswordChangedNotice implements ShouldQueue
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if ($user === null) {
            return;
        }

        Mail::to($user->email)->send(new PasswordChangedNoticeMail($user));
    }
}

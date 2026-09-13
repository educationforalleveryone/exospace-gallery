<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\PasswordResetMail;
use Illuminate\Auth\Notifications\ResetPassword as FrameworkResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetPassword extends FrameworkResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable)
    {
        return (new PasswordResetMail(
            $notifiable,
            $this->resetUrl($notifiable),
        ))->to($notifiable->email);
    }
}

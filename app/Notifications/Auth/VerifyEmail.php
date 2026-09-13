<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class VerifyEmail extends FrameworkVerifyEmail implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable)
    {
        return (new VerifyEmailMail(
            $notifiable,
            $this->verificationUrl($notifiable),
        ))->to($notifiable->email);
    }
}

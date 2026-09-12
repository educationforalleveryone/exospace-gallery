<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;

class VerifyEmail extends FrameworkVerifyEmail
{
    public function toMail($notifiable)
    {
        return (new VerifyEmailMail(
            $notifiable,
            $this->verificationUrl($notifiable),
        ))->to($notifiable->email);
    }
}

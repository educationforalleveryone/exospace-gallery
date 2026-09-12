<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\PasswordResetMail;
use Illuminate\Auth\Notifications\ResetPassword as FrameworkResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends FrameworkResetPassword
{
    public function toMail($notifiable)
    {
        return (new PasswordResetMail(
            $notifiable,
            $this->resetUrl($notifiable),
        ))->to($notifiable->email);
    }
}

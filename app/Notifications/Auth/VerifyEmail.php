<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Mail\VerifyEmailMail;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\URL;

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

    protected function verificationUrl($notifiable)
    {
        $canonical = rtrim((string) config('app.url'), '/');

        if ($canonical === '' || $canonical === rtrim(URL::to('/'), '/')) {
            return parent::verificationUrl($notifiable);
        }

        URL::forceRootUrl($canonical);
        URL::forceScheme(parse_url($canonical, PHP_URL_SCHEME) ?: 'https');

        try {
            return parent::verificationUrl($notifiable);
        } finally {
            URL::forceRootUrl(null);
            // AppServiceProvider forces https app-wide in production — restore
            // that instead of leaving the generator unforced for the rest of
            // this process's lifetime.
            URL::forceScheme(app()->isProduction() ? 'https' : null);
        }
    }
}

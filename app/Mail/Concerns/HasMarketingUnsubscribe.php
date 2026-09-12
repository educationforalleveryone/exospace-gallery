<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\User;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

trait HasMarketingUnsubscribe
{
    public function headers(): Headers
    {
        return new Headers(
            text: $this->unsubscribeHeaders($this->user),
        );
    }

    protected function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('unsubscribe.one-click', ['user' => $user->id], now()->addYear());
    }

    protected function unsubscribeHeaders(User $user): array
    {
        $url = $this->unsubscribeUrl($user);

        return [
            'List-Unsubscribe'        => "<{$url}>",
            'List-Unsubscribe-Post'   => 'List-Unsubscribe=One-Click',
        ];
    }
}

<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StampLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->id === null) {
            return;
        }

        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['last_login_at' => now()]);
        } catch (\Throwable $e) {
            Log::debug('StampLastLogin: could not record login time', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

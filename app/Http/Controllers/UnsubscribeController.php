<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(Request $request, User $user): View
    {
        // If already unsubscribed, show a friendly "already unsubscribed" page.
        if (! $user->marketing_consent) {
            return view('unsubscribe', [
                'user'     => $user,
                'already'  => true,
            ]);
        }

        return view('unsubscribe', [
            'user'     => $user,
            'already'  => false,
        ]);
    }

    public function confirm(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['marketing_consent' => false])->save();

        return redirect()->route('unsubscribe.done')
                         ->with('status', 'unsubscribed');
    }

    public function oneClickShow(Request $request, User $user): View
    {
        if ($user->marketing_consent) {
            $user->forceFill(['marketing_consent' => false])->save();
        }

        return view('unsubscribe-done', ['valid' => true]);
    }

    public function oneClickPost(Request $request, User $user): Response
    {
        if ($user->marketing_consent) {
            $user->forceFill(['marketing_consent' => false])->save();
        }

        return response('', 200);
    }

    public function done(Request $request): View
    {
        $status = session('status');

        if (! in_array($status, ['unsubscribed', 'already_unsubscribed'], true)) {
            return view('unsubscribe-done', ['valid' => false]);
        }

        return view('unsubscribe-done', ['valid' => true]);
    }
}

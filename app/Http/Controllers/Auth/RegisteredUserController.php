<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request): View
    {
        $invitation = $this->resolveInvitation($request->query('invitation'));

        // CONV-6: Capture redirect target for post-verification redirect.
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $redirect !== '' && mb_strlen($redirect) <= 2048) {
            $redirect = str_replace('\\', '/', $redirect);

            if (preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1) {
                return view('auth.register', [
                    'invitationToken' => $invitation ? (string) $request->query('invitation') : null,
                    'invitationEmail' => $invitation?->email,
                ]);
            }

            $redirect = trim($redirect);

            // Bare relative path (e.g. "billing/upgrade/pro") → prefix "/".
            if ($redirect !== '' && ! str_starts_with($redirect, '/')) {
                $redirect = '/'.$redirect;
            }

            if ($redirect !== ''
                && str_starts_with($redirect, '/')
                && ! str_starts_with($redirect, '//')
                && ! str_contains($redirect, '://')) {
                $request->session()->put('url.intended', $redirect);
            }
        }

        return view('auth.register', [
            'invitationToken' => $invitation ? (string) $request->query('invitation') : null,
            'invitationEmail' => $invitation?->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $invitation = $this->resolveInvitation($request->input('invitation_token'));

        // If registering via invitation, lock the email to the invited address
        if ($invitation) {
            $request->merge(['email' => $invitation->email]);
        }

        $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'email'             => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password'          => ['required', 'confirmed', Rules\Password::defaults()],
            'marketing_consent' => ['nullable', 'boolean'],
        ]);

        try {
            $user = User::create([
                'name'              => $request->name,
                'email'             => $request->email,
                'password'          => Hash::make($request->password),
                'marketing_consent' => $request->boolean('marketing_consent'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()
                ->withInput($request->only(['name', 'email']))
                ->withErrors([
                    'email' => trans('validation.unique', ['attribute' => 'email']),
                ]);
        }

        if ($invitation) {
            // Mark email as verified — the invitation proved ownership.
            $user->forceFill(['email_verified_at' => now()])->save();

            // Add user to the team
            $team = $invitation->team;
            $team->members()->attach($user->id, ['role' => $invitation->role]);
            $user->switchTeam($team);

            // Clean up the invitation
            $invitation->delete();

            $request->session()->regenerate();
            Auth::login($user);

            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "Welcome to {$team->name}! Your account is ready.");
        }

        // Normal registration — send verification email
        event(new Registered($user));

        $request->session()->regenerate();
        Auth::login($user);

        return redirect(route('verification.notice'));
    }

    private function resolveInvitation(?string $token): ?TeamInvitation
    {
        if (! $token) {
            return null;
        }

        $invitation = TeamInvitation::with('team')
                        ->where('token', TeamInvitation::hashToken($token))
                        ->first();

        return ($invitation && ! $invitation->isExpired()) ? $invitation : null;
    }
}

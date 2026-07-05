<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     * If an invitation token is in the query string, resolve the email
     * so we can pre-fill and lock the email field.
     *
     * CONV-6: If a ?redirect= query param is present, store it as the
     * session's intended URL so after registration + email verification,
     * the user lands on the intended page (e.g. billing/upgrade/pro).
     */
    public function create(Request $request): View
    {
        $invitation = $this->resolveInvitation($request->query('invitation'));

        // CONV-6: Capture redirect target for post-verification redirect.
        $redirect = $request->query('redirect');
        if ($redirect && is_string($redirect)) {
            if (! str_starts_with($redirect, '/')) {
                $redirect = '/' . ltrim($redirect, '/');
            }
            if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
                $request->session()->put('url.intended', $redirect);
            }
        }

        return view('auth.register', [
            'invitationToken' => $invitation?->token,
            'invitationEmail' => $invitation?->email,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
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
            // P0-3: marketing consent is optional (opt-in). When checked,
            // the user agrees to receive abandoned-cart recovery and
            // lifecycle nudge emails. CAN-SPAM/GDPR require explicit consent.
            'marketing_consent' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'              => $request->name,
            'email'             => $request->email,
            'password'          => Hash::make($request->password),
            'marketing_consent' => $request->boolean('marketing_consent'),
        ]);

        if ($invitation) {
            // Mark email as verified — the invitation proved ownership.
            $user->forceFill(['email_verified_at' => now()])->save();

            // Add user to the team
            $team = $invitation->team;
            $team->members()->attach($user->id, ['role' => $invitation->role]);
            $user->switchTeam($team);

            // Clean up the invitation
            $invitation->delete();

            // Do NOT fire the Registered event here — it would trigger
            // the verification email. The welcome email boot hook also
            // skips invited users. Everything is intentionally silent.
            Auth::login($user);

            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "Welcome to {$team->name}! Your account is ready.");
        }

        // Normal registration — send verification email
        event(new Registered($user));
        Auth::login($user);

        return redirect(route('verification.notice'));
    }

    /**
     * Look up a valid (non-expired) invitation by token.
     */
    private function resolveInvitation(?string $token): ?\App\Models\TeamInvitation
    {
        if (! $token) {
            return null;
        }

        $invitation = \App\Models\TeamInvitation::with('team')
                        ->where('token', $token)
                        ->first();

        return ($invitation && ! $invitation->isExpired()) ? $invitation : null;
    }
}

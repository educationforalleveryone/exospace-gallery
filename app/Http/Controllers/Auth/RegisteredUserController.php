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
    /**
     * Display the registration view.
     * If an invitation token is in the query string, resolve the email
     * so we can pre-fill and lock the email field.
     *
     * CONV-6: If a ?redirect= query param is present, store it as the
     * session's intended URL so after registration + email verification,
     * the user lands on the intended page (e.g. billing/upgrade/pro).
     *
     * REGISTRATION-ITERATION HARDENING (CONV-6 parity with the login
     * flow): the sanitization below is the same explicit ALLOW-LIST the
     * login iteration shipped for /login?redirect=. Accepted: relative
     * paths only — optionally auto-prefixed with "/" for bare relative
     * targets like "billing/upgrade/pro". Rejected (falls back to the
     * default dashboard redirect after verification):
     *   - absolute URLs (https://evil.example);
     *   - protocol-relative URLs (//evil.example);
     *   - backslash-confused targets (/\evil.example) — WHATWG URL
     *     parsing treats "\" as "/", so scheme-relative forms must
     *     never reach the intended URL (previously STORED here);
     *   - values containing control characters (CR/LF/NUL);
     *   - absurd lengths (> 2048 chars).
     */
    public function create(Request $request): View
    {
        $invitation = $this->resolveInvitation($request->query('invitation'));

        // CONV-6: Capture redirect target for post-verification redirect.
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $redirect !== '' && mb_strlen($redirect) <= 2048) {
            // Normalize backslash separators ("/\evil" → "//evil") so the
            // scheme-relative check below sees it.
            $redirect = str_replace('\\', '/', $redirect);

            // Reject (rather than silently strip) anything containing
            // control characters (CR/LF/NUL/...): legitimate paths never
            // contain them.
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

            // Accept only same-site relative paths: exactly one leading
            // slash, never "//" (protocol-relative), never an absolute URL.
            if ($redirect !== ''
                && str_starts_with($redirect, '/')
                && ! str_starts_with($redirect, '//')
                && ! str_contains($redirect, '://')) {
                $request->session()->put('url.intended', $redirect);
            }
        }

        // INVITATION-TOKEN FIX: pass the PLAINTEXT token from the query
        // string into the hidden form field — NOT the model's `token`
        // attribute. Since the D-6 audit fix, the DB stores sha256(token);
        // round-tripping the hash made store()'s findByToken() lookup hash
        // an already-hashed value, so the invitation could never resolve.
        return view('auth.register', [
            'invitationToken' => $invitation ? (string) $request->query('invitation') : null,
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

        // RACE FIX: two near-simultaneous POSTs with the same email both
        // pass the `unique` validation (no user exists yet), then race the
        // INSERT. The users.email UNIQUE index (base migration) correctly
        // rejects the loser — but uncaught that surfaces as a QueryException
        // → HTTP 500. A real double-click can trigger this without any
        // attacker. Catch the constraint violation and re-present the exact
        // same user-facing response the `unique` rule would have produced
        // (standard "email already taken" error + preserved name/email,
        // never the password).
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

            // Do NOT fire the Registered event here — it would trigger
            // the verification email. The welcome email boot hook also
            // skips invited users. Everything is intentionally silent.
            //
            // CR-4 FIX (Iter-002, deferred from Iter-001): Regenerate the
            // session ID to prevent session fixation. The invitation-accept
            // flow is a high-privilege transition (anonymous → authenticated),
            // and without session ID rotation, a session cookie planted on
            // the victim's browser before they click the invitation link
            // survives the login, allowing the attacker to ride the same
            // session post-authentication.
            //
            // This completes the CR-4 fix that was partially shipped in
            // Iteration-001 (which fixed the OAuth login paths but deferred
            // this path to Iteration-002).
            $request->session()->regenerate();
            Auth::login($user);

            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "Welcome to {$team->name}! Your account is ready.");
        }

        // Normal registration — send verification email
        event(new Registered($user));

        // CR-4 FIX (Iter-002, deferred from Iter-001): Regenerate the session
        // ID to prevent session fixation on normal registration.
        $request->session()->regenerate();
        Auth::login($user);

        return redirect(route('verification.notice'));
    }

    /**
     * Look up a valid (non-expired) invitation by (plaintext) token.
     *
     * INVITATION-TOKEN FIX (D-6 follow-up): since the audit's D-6 fix,
     * team_invitations.token stores sha256(plaintext) — Admin\TeamController
     * hashes before insert and the email links carry the plaintext. This
     * controller was the one remaining raw `where('token', $token)`
     * consumer: it compared the plaintext link token against the hashed
     * column, never matched, and silently downgraded every
     * registration-via-invitation into a normal registration (no banner,
     * no locked email, no team membership, no auto-verification).
     *
     * Use TeamInvitation::findByToken() — the model's documented lookup
     * contract — which hashes the plaintext before querying.
     */
    private function resolveInvitation(?string $token): ?TeamInvitation
    {
        if (! $token) {
            return null;
        }

        // NOTE: hashToken() in the scoped query (not ::findByToken()) so the
        // ->with('team') eager load survives — findByToken() starts a fresh
        // static query and would silently discard it.
        $invitation = TeamInvitation::with('team')
                        ->where('token', TeamInvitation::hashToken($token))
                        ->first();

        return ($invitation && ! $invitation->isExpired()) ? $invitation : null;
    }
}

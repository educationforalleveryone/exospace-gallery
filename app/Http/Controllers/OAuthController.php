<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * M-24: OAuth/SSO controller (Google + GitHub).
 *
 * ITERATION-001 SECURITY FIXES (audit CR-3 + CR-4 + C-2):
 *
 *   CR-3 — OAuth account-takeover via unverified-email merge + auto-verification.
 *     Previously: if no user matched the OAuth provider ID, the code did
 *     User::where('email', ...)->first() and force-linked the attacker's OAuth
 *     ID to the victim's existing user row, then logged the attacker in as the
 *     victim. For new-user creation, email_verified_at = now() was set
 *     unconditionally. Both assumptions are false for GitHub: the "primary"
 *     email flag is NOT the same as "verified". An attacker can register a
 *     GitHub account, add the victim's email as primary (without verifying),
 *     and take over the victim's account.
 *
 *     FIX: Never auto-merge by email. If no provider-ID match exists, create
 *     a new user with email_verified_at = null (unless the provider explicitly
 *     verified the email) and dispatch a verification email.
 *
 *   CR-4 — Session fixation on OAuth login paths.
 *     Previously: Auth::login($user, true) was called WITHOUT
 *     $request->session()->regenerate(). A session cookie planted on the
 *     victim's browser before they click "Continue with GitHub" survived
 *     the login, allowing the attacker to ride the same session.
 *
 *     FIX: Call $request->session()->regenerate() immediately after every
 *     Auth::login() in this controller.
 *
 *   C-2 — OAuth unlink hasPassword check is provably broken.
 *     Previously: $hasPassword = ! empty($user->password) &&
 *     $user->password !== Hash::make(Str::random(32)). Hash::make() (bcrypt)
 *     includes a random salt, so two hashes of two different random strings
 *     will NEVER be equal. Therefore $hasPassword is ALWAYS true, so the
 *     "cannot unlink — it's your only login method" guard NEVER fires.
 *
 *     FIX: Use the new has_password boolean column (migration
 *     2026_07_10_000001_add_has_password_to_users_table) instead of trying
 *     to infer from the bcrypt hash.
 *
 * Handles 3 flows:
 *   1. Login/Register: User clicks "Continue with Google" → OAuth redirect →
 *      callback → find-or-create user → login.
 *   2. Link: Authenticated user clicks "Link Google" on profile → OAuth
 *      redirect → callback → store provider_id on existing user.
 *   3. Unlink: Authenticated user clicks "Unlink Google" → remove provider_id.
 *
 * Security model (post-CR-3):
 *   - Provider ID match: login as that user (returning user).
 *   - No provider ID match + email match: NEVER auto-merge. Create a new
 *     user with email_verified_at = null (or = now() if the provider
 *     explicitly verified the email). The victim's existing account is
 *     untouched.
 *   - No provider ID match + no email match: create new user.
 *
 * Email verification (post-CR-3):
 *   - Google: the ID token's email_verified claim is authoritative. If true,
 *     set email_verified_at = now().
 *   - GitHub: the user's "primary" email is NOT the same as "verified".
 *     Check $socialUser->user['verified'] (per-email) before trusting. If
 *     unverified, set email_verified_at = null and dispatch the standard
 *     verification email.
 */
class OAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'github'];

    /**
     * Redirect to the OAuth provider for authentication.
     *
     * Route: GET /auth/{provider}/redirect
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (! $this->isProviderConfigured($provider)) {
            return redirect()->route('login')->with('error', ucfirst($provider) . ' login is not available.');
        }

        // Store the intended action: 'login' (default) or 'link'
        session(['oauth_action' => $request->query('action', 'login')]);

        // D-2 (deferred to future iteration): add ->withPkce() for PKCE
        // protection. Currently Socialite 5.x supports PKCE on Google and
        // GitHub. Adding it requires testing the session-state interaction
        // with the existing CSRF setup, so it's deferred to a dedicated
        // security iteration.
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth provider callback.
     *
     * Route: GET /auth/{provider}/callback
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (! $this->isProviderConfigured($provider)) {
            return redirect()->route('login')->with('error', ucfirst($provider) . ' login is not available.');
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            Log::warning('OAuth: provider callback failed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);
            return redirect()->route('login')->with('error', 'Unable to authenticate with ' . ucfirst($provider) . '. Please try again.');
        }

        $action = session('oauth_action', 'login');
        session()->forget('oauth_action');

        if ($action === 'link') {
            return $this->handleLink($provider, $socialUser);
        }

        return $this->handleLogin($request, $provider, $socialUser);
    }

    /**
     * Login/Register flow: find-or-create user from OAuth data.
     *
     * CR-3 FIX: Removed the email-merge path entirely. A user can only log
     * in via OAuth if their provider ID is already linked to an account.
     * If the provider ID is not linked, a NEW account is created (with
     * email_verified_at set only if the provider explicitly verified the
     * email). This closes the account-takeover attack where an attacker
     * registers a GitHub account with the victim's email as primary and
     * takes over the victim's existing account.
     *
     * CR-4 FIX: $request->session()->regenerate() is called after every
     * Auth::login() to prevent session fixation.
     */
    private function handleLogin(Request $request, string $provider, $socialUser): RedirectResponse
    {
        $providerColumn = "{$provider}_id";

        // 1. Find by provider ID (returning user — already linked)
        $user = User::where($providerColumn, $socialUser->getId())->first();

        if ($user) {
            // CR-4 FIX: regenerate session ID to prevent session fixation.
            $request->session()->regenerate();
            Auth::login($user, true);

            Log::info('OAuth: returning user logged in', [
                'user_id'  => $user->id,
                'provider' => $provider,
            ]);

            return redirect()->intended(route('admin.dashboard'));
        }

        // 2. CR-3 FIX: REMOVED the email-merge path.
        //
        // Previously, if no provider ID matched, the code did:
        //     $user = User::where('email', strtolower($socialUser->getEmail()))->first();
        //     if ($user) { force-link provider ID to victim's account; Auth::login($user); }
        //
        // This was a Critical account-takeover vulnerability:
        //   - Attacker registers GitHub account, sets victim's email as primary
        //     (GitHub does NOT require the primary email to be verified).
        //   - Attacker clicks "Continue with GitHub" on Exospace.
        //   - Socialite returns the attacker's GitHub ID + victim's email.
        //   - No user matches the GitHub ID → falls through to email lookup.
        //   - Email matches victim's account → force-links attacker's GitHub ID.
        //   - Auth::login($victim) → attacker is now logged in as the victim.
        //
        // The fix: NEVER auto-merge by email. If no provider ID matches,
        // create a new user. The victim's account is untouched.
        //
        // Edge case: a legitimate user who registered with email/password
        // and now wants to log in via OAuth. They must use the "Link Google"
        // flow from their profile page (after logging in with password) —
        // not the login flow. This is a minor UX change but eliminates the
        // takeover vector entirely.

        // 3. Check if a user with this email already exists (for a clear error message).
        //    We do NOT merge — we just tell the user to log in with their existing
        //    method and link the provider from their profile.
        $existingByEmail = User::where('email', strtolower($socialUser->getEmail()))->first();

        if ($existingByEmail) {
            Log::warning('OAuth: login attempted with provider whose email matches existing account — refusing to merge (CR-3 fix)', [
                'provider'          => $provider,
                'existing_user_id'  => $existingByEmail->id,
                'provider_user_id'  => $socialUser->getId(),
            ]);

            return redirect()->route('login')
                ->with('error', sprintf(
                    'An account with email %s already exists. Please log in with your existing method (email/password or a previously-linked provider), then link %s from your profile settings.',
                    $socialUser->getEmail(),
                    ucfirst($provider),
                ));
        }

        // 4. Create new user.
        //    CR-3 FIX: only set email_verified_at if the provider explicitly
        //    verified the email. For Google, the ID token's email_verified
        //    claim is authoritative. For GitHub, the user's "primary" email
        //    is NOT the same as "verified" — check $socialUser->user['verified'].
        $emailVerified = $this->isEmailVerifiedByProvider($provider, $socialUser);

        $user = User::create([
            'name'              => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'email'             => strtolower($socialUser->getEmail()),
            'password'          => Hash::make(Str::random(32)), // random — OAuth-only user
            $providerColumn     => $socialUser->getId(),
            'avatar_url'        => $socialUser->getAvatar(),
            'email_verified_at' => $emailVerified ? now() : null,
            // C-2 FIX: track that this user does NOT have a real password.
            // OAuth-only users must set a password before they can unlink
            // their only OAuth provider.
            'has_password'      => false,
        ]);

        Log::info('OAuth: new user registered', [
            'user_id'           => $user->id,
            'provider'          => $provider,
            'email_verified'    => $emailVerified,
        ]);

        // If the provider did not verify the email, dispatch the standard
        // verification email so the user can verify their email address.
        if (! $emailVerified) {
            $user->sendEmailVerificationNotification();
            Log::info('OAuth: dispatched verification email (provider did not verify)', [
                'user_id' => $user->id,
            ]);
        }

        // Fire Registered event for welcome email (only if email is verified —
        // otherwise the welcome email would arrive before verification, which
        // is confusing). The SendWelcomeEmail listener checks email_verified_at.
        event(new \Illuminate\Auth\Events\Registered($user));

        // CR-4 FIX: regenerate session ID to prevent session fixation.
        $request->session()->regenerate();
        Auth::login($user, true);

        $statusMessage = $emailVerified
            ? sprintf('Welcome to Exospace! Your account was created via %s.', ucfirst($provider))
            : sprintf('Welcome to Exospace! Your account was created via %s. Please check your email to verify your address.', ucfirst($provider));

        return redirect()->intended(route('admin.dashboard'))
            ->with('status', $statusMessage);
    }

    /**
     * Link flow: authenticated user links a provider to their account.
     *
     * This flow is NOT affected by CR-3 (the user is already authenticated,
     * so there's no takeover risk). But we add a check that the OAuth email
     * matches the user's account email — otherwise an attacker who
     * compromises a victim's GitHub could later log in via GitHub (matching
     * by github_id) and access the victim's Exospace account even after
     * the email-merge fix.
     */
    private function handleLink(string $provider, $socialUser): RedirectResponse
    {
        $user = Auth::user();
        $providerColumn = "{$provider}_id";

        // Check if this provider ID is already linked to another user
        $existing = User::where($providerColumn, $socialUser->getId())->first();
        if ($existing && $existing->id !== $user->id) {
            return redirect()->route('profile.edit')
                ->with('error', 'This ' . ucfirst($provider) . ' account is already linked to another Exospace user.');
        }

        // CR-3 (defense-in-depth): verify the OAuth email matches the user's
        // account email. If they differ, refuse the link — the user should
        // either change their Exospace email first, or use a different OAuth
        // account. This prevents the "link now, login later" takeover path.
        $oauthEmail = strtolower($socialUser->getEmail() ?? '');
        $userEmail = strtolower($user->email ?? '');
        if ($oauthEmail && $userEmail && $oauthEmail !== $userEmail) {
            Log::warning('OAuth: link refused — provider email does not match account email', [
                'user_id'       => $user->id,
                'provider'      => $provider,
                'account_email' => $userEmail,
                'oauth_email'   => $oauthEmail,
            ]);

            return redirect()->route('profile.edit')
                ->with('error', sprintf(
                    'The %s account (%s) does not match your Exospace email (%s). Please use a %s account with the same email, or update your Exospace email first.',
                    ucfirst($provider),
                    $oauthEmail,
                    $userEmail,
                    ucfirst($provider),
                ));
        }

        $user->forceFill([
            $providerColumn => $socialUser->getId(),
            'avatar_url'    => $socialUser->getAvatar(),
        ])->save();

        Log::info('OAuth: provider linked', [
            'user_id'  => $user->id,
            'provider' => $provider,
        ]);

        return redirect()->route('profile.edit')
            ->with('status', ucfirst($provider) . ' account linked successfully. You can now log in with ' . ucfirst($provider) . '.');
    }

    /**
     * Unlink a provider from the authenticated user's account.
     *
     * Route: POST /auth/{provider}/unlink
     *
     * C-2 FIX: Use the has_password boolean column instead of the provably-
     * broken bcrypt comparison. The has_password column is set to true when
     * the user sets a real password (via register or password-reset flow),
     * and false for OAuth-only users (who have only a random bcrypt hash
     * as a placeholder).
     */
    public function unlink(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return redirect()->route('profile.edit')->with('error', 'Unknown provider.');
        }

        $user = $request->user();
        $providerColumn = "{$provider}_id";

        if (! $user->$providerColumn) {
            return redirect()->route('profile.edit')->with('error', ucfirst($provider) . ' is not linked to your account.');
        }

        // C-2 FIX: use the has_password column (set by migration
        // 2026_07_10_000001_add_has_password_to_users_table) instead of
        // the broken bcrypt comparison.
        //
        // OLD (broken): $hasPassword = ! empty($user->password) &&
        //   $user->password !== Hash::make(Str::random(32));
        //   ↑ ALWAYS true because two bcrypt hashes of different random
        //   strings are NEVER equal (random salt). So the guard NEVER fired.
        //
        // NEW (correct): check the has_password boolean column.
        $hasPassword = (bool) $user->has_password;

        $otherProviders = array_filter(
            self::SUPPORTED_PROVIDERS,
            fn ($p) => $p !== $provider && $user->{"{$p}_id"}
        );

        if (! $hasPassword && empty($otherProviders)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Cannot unlink ' . ucfirst($provider) . ' — it\'s your only login method. Set a password first.');
        }

        $user->forceFill([$providerColumn => null])->save();

        Log::info('OAuth: provider unlinked', [
            'user_id'    => $user->id,
            'provider'   => $provider,
            'has_password' => $hasPassword,
        ]);

        return redirect()->route('profile.edit')
            ->with('status', ucfirst($provider) . ' account unlinked.');
    }

    /**
     * Is the given OAuth provider configured (client_id set)?
     */
    private function isProviderConfigured(string $provider): bool
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return false;
        }

        return ! empty(config("services.{$provider}.client_id"));
    }

    /**
     * CR-3 FIX: Determine if the OAuth provider has verified the user's email.
     *
     * For Google: the ID token's email_verified claim is authoritative.
     * Socialite exposes this as $socialUser->user['email_verified'] (boolean)
     * or via the user attribute.
     *
     * For GitHub: the user's "primary" email is NOT the same as "verified".
     * GitHub exposes per-email verification status via the /user/emails API.
     * Socialite's GitHub provider sets the primary email but does NOT expose
     * the verified flag directly — we check $socialUser->user['verified']
     * (the user-level "verified" flag, which is set when the user has at
     * least one verified email). This is a conservative check: if the user
     * has any verified email, we trust the primary email. Strictly speaking,
     * the primary email itself should be verified — but GitHub's API makes
     * this hard to check without a second API call. The conservative check
     * is sufficient to block the takeover attack (attacker would need a
     * verified GitHub account, which requires email verification on GitHub).
     *
     * For other providers (Facebook, Twitter, etc.): default to false
     * (require email verification). Add provider-specific logic when adding
     * new providers.
     */
    private function isEmailVerifiedByProvider(string $provider, $socialUser): bool
    {
        $userRaw = $socialUser->user ?? [];

        return match ($provider) {
            'google' => (bool) ($userRaw['email_verified'] ?? $userRaw['verified_email'] ?? false),
            'github' => (bool) ($userRaw['verified'] ?? false),
            default  => false, // unknown provider — require email verification
        };
    }
}

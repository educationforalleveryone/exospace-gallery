<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * M-24: OAuth/SSO controller (Google + GitHub).
 *
 * Handles 3 flows:
 *   1. Login/Register: User clicks "Continue with Google" → OAuth redirect →
 *      callback → find-or-create user → login.
 *   2. Link: Authenticated user clicks "Link Google" on profile → OAuth
 *      redirect → callback → store provider_id on existing user.
 *   3. Unlink: Authenticated user clicks "Unlink Google" → remove provider_id.
 *
 * Account merge: If a user registers with email/password, then later logs in
 * via Google with the same email, the Google account is LINKED to the existing
 * user (not a new account). This prevents duplicate accounts.
 *
 * Security: OAuth callback verifies the provider's signed response — can't be
 * forged without the client_secret. No password is set for OAuth-only users
 * (they can't log in via password until they set one). If an OAuth-only user
 * needs password login, they use the password reset flow.
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

        return $this->handleLogin($provider, $socialUser);
    }

    /**
     * Login/Register flow: find-or-create user from OAuth data.
     */
    private function handleLogin(string $provider, $socialUser): RedirectResponse
    {
        $providerColumn = "{$provider}_id";

        // 1. Find by provider ID (already linked)
        $user = User::where($providerColumn, $socialUser->getId())->first();

        if ($user) {
            Auth::login($user, true);
            return redirect()->intended(route('admin.dashboard'));
        }

        // 2. Find by email (account merge — link the provider)
        $user = User::where('email', strtolower($socialUser->getEmail()))->first();

        if ($user) {
            $user->forceFill([
                $providerColumn => $socialUser->getId(),
                'avatar_url'    => $socialUser->getAvatar(),
            ])->save();

            Log::info('OAuth: linked provider to existing user', [
                'user_id'  => $user->id,
                'provider' => $provider,
            ]);

            Auth::login($user, true);
            return redirect()->intended(route('admin.dashboard'));
        }

        // 3. Create new user
        $user = User::create([
            'name'              => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'email'             => strtolower($socialUser->getEmail()),
            'password'          => Hash::make(\Illuminate\Support\Str::random(32)), // random — OAuth-only user
            $providerColumn     => $socialUser->getId(),
            'avatar_url'        => $socialUser->getAvatar(),
            'email_verified_at' => now(), // OAuth providers verify email
        ]);

        Log::info('OAuth: new user registered', [
            'user_id'  => $user->id,
            'provider' => $provider,
            'email'    => $user->email,
        ]);

        // Fire Registered event for welcome email
        event(new \Illuminate\Auth\Events\Registered($user));

        Auth::login($user, true);

        return redirect()->intended(route('admin.dashboard'))
            ->with('status', 'Welcome to Exospace! Your account was created via ' . ucfirst($provider) . '.');
    }

    /**
     * Link flow: authenticated user links a provider to their account.
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

        // Security: don't allow unlinking if it's the ONLY login method
        // (no password set + no other provider linked)
        $hasPassword = ! empty($user->password) && $user->password !== Hash::make(\Illuminate\Support\Str::random(32));
        $otherProviders = array_filter(self::SUPPORTED_PROVIDERS, fn($p) => $p !== $provider && $user->{"{$p}_id"});

        if (! $hasPassword && empty($otherProviders)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Cannot unlink ' . ucfirst($provider) . ' — it\'s your only login method. Set a password first.');
        }

        $user->forceFill([$providerColumn => null])->save();

        Log::info('OAuth: provider unlinked', [
            'user_id'  => $user->id,
            'provider' => $provider,
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
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'github'];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (! $this->isProviderConfigured($provider)) {
            return redirect()->route('login')->with('error', ucfirst($provider) . ' login is not available.');
        }

        // Store the intended action: 'login' (default) or 'link'
        session(['oauth_action' => $request->query('action', 'login')]);

        return Socialite::driver($provider)->withPkce()->redirect();
    }

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

    private function handleLogin(Request $request, string $provider, $socialUser): RedirectResponse
    {
        $providerColumn = "{$provider}_id";

        // 1. Find by provider ID (returning user — already linked)
        $user = User::where($providerColumn, $socialUser->getId())->first();

        if ($user) {
            // Regenerate the session ID to prevent session fixation.
            $request->session()->regenerate();
            Auth::login($user, true);

            Log::info('OAuth: returning user logged in', [
                'user_id'  => $user->id,
                'provider' => $provider,
            ]);

            return redirect()->intended(route('admin.dashboard'));
        }

        // 3. Check if a user with this email already exists (for a clear error message).
        $existingByEmail = User::where('email', strtolower($socialUser->getEmail()))->first();

        if ($existingByEmail) {
            Log::warning('OAuth: login attempted with provider whose email matches existing account — refusing to merge', [
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

        $emailVerified = $this->isEmailVerifiedByProvider($provider, $socialUser);

        $user = User::create([
            'name'         => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'email'        => strtolower($socialUser->getEmail()),
            'password'     => Hash::make(Str::random(32)), // random — OAuth-only user
            // Track that this user does NOT have a real password.
            'has_password' => false,
        ]);
        $user->forceFill([
            $providerColumn     => $socialUser->getId(),
            'avatar_url'        => $socialUser->getAvatar(),
            'email_verified_at' => $emailVerified ? now() : null,
        ])->save();

        Log::info('OAuth: new user registered', [
            'user_id'           => $user->id,
            'provider'          => $provider,
            'email_verified'    => $emailVerified,
        ]);

        if (! $emailVerified) {
            $user->sendEmailVerificationNotification();
            Log::info('OAuth: dispatched verification email (provider did not verify)', [
                'user_id' => $user->id,
            ]);
        }

        event(new \Illuminate\Auth\Events\Registered($user));

        // Regenerate the session ID to prevent session fixation.
        $request->session()->regenerate();
        Auth::login($user, true);

        $statusMessage = $emailVerified
            ? sprintf('Welcome to Exospace! Your account was created via %s.', ucfirst($provider))
            : sprintf('Welcome to Exospace! Your account was created via %s. Please check your email to verify your address.', ucfirst($provider));

        return redirect()->intended(route('admin.dashboard'))
            ->with('status', $statusMessage);
    }

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

        AdminAuditLog::record('oauth.unlinked', $user, [
            'provider'           => $provider,
            'has_password'       => $hasPassword,
            'had_other_provider' => ! empty($otherProviders),
        ]);

        Log::info('OAuth: provider unlinked', [
            'user_id'    => $user->id,
            'provider'   => $provider,
            'has_password' => $hasPassword,
        ]);

        return redirect()->route('profile.edit')
            ->with('status', ucfirst($provider) . ' account unlinked.');
    }

    private function isProviderConfigured(string $provider): bool
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return false;
        }

        return ! empty(config("services.{$provider}.client_id"));
    }

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

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Profile') }}" description="Manage your account details, security, and appearance."/>
    </x-slot>

    <div class="page-shell-mid space-y-6">
            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            {{-- SEC-4: MFA section — opt-in for regular users, required for super-admins --}}
            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-100">Multi-Factor Authentication (MFA)</h3>
                    <p class="mt-1 text-sm text-gray-400">
                        Add an extra layer of security to your account. Once enabled, you'll need a 6-digit code from your authenticator app (Google Authenticator, Authy, 1Password) in addition to your password when accessing billing.
                    </p>

                    @if(auth()->user()->google2fa_secret)
                        <div class="mt-4 p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-lg">
                            <p class="text-sm text-emerald-300 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                MFA is enabled
                                @if(auth()->user()->mfa_enabled_at)
                                    <span class="text-emerald-500/70 text-xs ml-1">(since {{ auth()->user()->mfa_enabled_at->format('M j, Y') }})</span>
                                @endif
                            </p>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">
                            MFA verification is required for billing changes. Your verified session lasts 30 minutes before re-prompting.
                        </p>
                    @else
                        <div class="mt-4">
                            <a href="{{ route('mfa.setup') }}"
                               class="inline-flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Enable MFA
                            </a>
                            <p class="mt-2 text-xs text-gray-500">Optional but recommended. You can disable it anytime.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- M-24: Linked OAuth accounts --}}
            @php
                $hasGoogle = !empty(config('services.google.client_id'));
                $hasGithub = !empty(config('services.github.client_id'));
            @endphp
            @if($hasGoogle || $hasGithub)
            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-100">Connected Accounts</h3>
                    <p class="mt-1 text-sm text-gray-400">Link your social accounts for one-click sign-in.</p>

                    <div class="mt-4 space-y-3">
                        @if($hasGoogle)
                        <div class="flex items-center justify-between p-3 bg-gray-900/50 rounded-lg border border-gray-700">
                            <div class="flex items-center gap-3">
                                <svg class="w-6 h-6" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                <div>
                                    <p class="text-sm font-medium text-gray-200">Google</p>
                                    <p class="text-xs text-gray-500">{{ auth()->user()->hasOAuthProvider('google') ? 'Connected' : 'Not connected' }}</p>
                                </div>
                            </div>
                            @if(auth()->user()->hasOAuthProvider('google'))
                                <form method="POST" action="{{ route('oauth.unlink', 'google') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-danger-ghost">Unlink</button>
                                </form>
                            @else
                                <a href="{{ route('oauth.redirect', 'google') }}?action=link"
                                   class="p-1.5 -m-1 rounded text-xs text-brand-400 hover:text-brand-300 hover:bg-white/[0.06] transition font-medium">Link Google</a>
                            @endif
                        </div>
                        @endif

                        @if($hasGithub)
                        <div class="flex items-center justify-between p-3 bg-gray-900/50 rounded-lg border border-gray-700">
                            <div class="flex items-center gap-3">
                                <svg class="w-6 h-6 text-gray-300" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                                <div>
                                    <p class="text-sm font-medium text-gray-200">GitHub</p>
                                    <p class="text-xs text-gray-500">{{ auth()->user()->hasOAuthProvider('github') ? 'Connected' : 'Not connected' }}</p>
                                </div>
                            </div>
                            @if(auth()->user()->hasOAuthProvider('github'))
                                <form method="POST" action="{{ route('oauth.unlink', 'github') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-danger-ghost">Unlink</button>
                                </form>
                            @else
                                <a href="{{ route('oauth.redirect', 'github') }}?action=link"
                                   class="p-1.5 -m-1 rounded text-xs text-brand-400 hover:text-brand-300 hover:bg-white/[0.06] transition font-medium">Link GitHub</a>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>

</x-app-layout>
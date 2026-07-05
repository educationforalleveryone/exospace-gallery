<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
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
                               class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Enable MFA
                            </a>
                            <p class="mt-2 text-xs text-gray-500">Optional but recommended. You can disable it anytime.</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-gray-800 shadow-lg sm:rounded-lg border border-gray-700">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
<x-guest-layout>
    @if(!isset($invitationToken))
    <div class="text-center mb-5">
        <p class="text-base font-bold text-gray-100">Create your free account</p>
        <p class="text-xs text-gray-500 mt-1">Build a 3D gallery in under 5 minutes — no design skills needed.</p>
    </div>
    @endif
    <form method="POST" action="{{ route('register') }}">
        @csrf

        {{-- Pass invitation token through if present --}}
        @if(isset($invitationToken))
            <input type="hidden" name="invitation_token" value="{{ $invitationToken }}">

            <div class="mb-4 p-3 bg-indigo-900/40 border border-indigo-700/50 rounded-lg text-center">
                <p class="text-indigo-300 text-sm">
                    🎉 You were invited to join a team. Create your account to accept.
                </p>
            </div>
        @endif

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            @if(isset($invitationEmail))
                {{-- Email is locked to the invitation address --}}
                <x-text-input id="email" class="block mt-1 w-full opacity-75 cursor-not-allowed" type="email" name="email"
                    :value="$invitationEmail" required autocomplete="username" readonly />
                <p class="mt-1 text-xs text-gray-500">This email is tied to your invitation and cannot be changed.</p>
            @else
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                    :value="old('email')" required autocomplete="username" />
            @endif
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <!-- Marketing Consent (P0-3: CAN-SPAM/GDPR opt-in) -->
        <div class="mt-4">
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox"
                       name="marketing_consent"
                       value="1"
                       @checked(old('marketing_consent'))
                       class="checkbox-base mt-1" />
                <span class="text-xs text-gray-400 leading-relaxed">
                    Send me occasional product tips and reminders (e.g. if I start an upgrade but don't finish).
                    I can unsubscribe at any time via the link in every email.
                </span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-400 hover:text-gray-300 rounded-md" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ isset($invitationToken) ? __('Create account & join team') : __('Create account') }}
            </x-primary-button>
        </div>

        <div class="mt-6 text-center">
            <p class="text-xs text-gray-500">
                By registering, you agree to our
                <a href="{{ route('terms') }}" class="text-purple-400 hover:text-purple-300">Terms of Service</a>
                and
                <a href="{{ route('privacy') }}" class="text-purple-400 hover:text-purple-300">Privacy Policy</a>
            </p>
        </div>
    </form>

    {{-- M-24: OAuth/SSO buttons --}}
    @php
        $hasGoogle = !empty(config('services.google.client_id'));
        $hasGithub = !empty(config('services.github.client_id'));
    @endphp
    @if($hasGoogle || $hasGithub)
    <div class="mt-6">
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-gray-700"></div>
            </div>
            <div class="relative flex justify-center text-xs">
                <span class="bg-gray-900 px-3 text-gray-500">or sign up with</span>
            </div>
        </div>

        <div class="mt-4 flex gap-3 justify-center">
            @if($hasGoogle)
            <a href="{{ route('oauth.redirect', 'google') }}"
               class="flex items-center gap-2 px-4 py-2.5 bg-gray-800 hover:bg-gray-700 border border-gray-600 rounded-lg text-sm text-gray-200 transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                Google
            </a>
            @endif
            @if($hasGithub)
            <a href="{{ route('oauth.redirect', 'github') }}"
               class="flex items-center gap-2 px-4 py-2.5 bg-gray-800 hover:bg-gray-700 border border-gray-600 rounded-lg text-sm text-gray-200 transition">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                GitHub
            </a>
            @endif
        </div>
    </div>
    @endif
</x-guest-layout>

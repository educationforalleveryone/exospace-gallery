<x-guest-layout>
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

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-400 hover:text-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ isset($invitationToken) ? __('Create Account & Join Team') : __('Register') }}
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
</x-guest-layout>
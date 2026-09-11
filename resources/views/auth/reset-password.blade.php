<x-guest-layout>
    <h1 class="sr-only">{{ __('Reset Password') }}</h1>
    <!-- RESET-ITERATION FIX (UX): guard against duplicate submissions —
         mirrors the login/register/forgot-password forms. The page fully
         reloads on failure (validation redirect) so the state self-resets. -->
    <form method="POST" action="{{ route('password.store') }}"
          x-data="{ submitting: false }"
          x-on:submit="submitting = true">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button x-bind:disabled="submitting"
                              x-bind:class="{ 'opacity-50 cursor-not-allowed': submitting }">
                {{ __('Reset Password') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
<x-guest-layout>
    <h1 class="sr-only">{{ __('Forgot Password') }}</h1>
    <div class="mb-4 text-sm text-gray-400 leading-relaxed">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <!-- RESET-ITERATION FIX (UX): guard against duplicate submissions —
         double-clicks burned the route's 5/hour throttle budget and made
         the broker's 60s per-email throttle look like a broken flow.
         x-on:submit (not inline onsubmit=) keeps this CSP-safe; mirrors the
         login/register forms. The page fully reloads on failure
         (validation redirect) so the state self-resets. -->
    <form method="POST" action="{{ route('password.email') }}"
          x-data="{ submitting: false }"
          x-on:submit="submitting = true">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button x-bind:disabled="submitting"
                              x-bind:class="{ 'opacity-50 cursor-not-allowed': submitting }">
                {{ __('Email Password Reset Link') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
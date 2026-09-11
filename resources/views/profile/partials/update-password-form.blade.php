<section>
    <header>
        <h2 class="text-lg font-medium text-gray-100">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-1 text-sm text-gray-400">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    {{-- ITERATION-8: OAuth-only accounts (has_password=false) hold only an
         unusable random placeholder hash — the "current password" check can
         never pass for them, so the form below was a permanent dead end.
         Point them at the app's actual set-a-password path (the forgot-
         password flow, which issues a real credential) instead of rendering
         a form that always fails. --}}
    @if(auth()->user()->has_password)
    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6" data-busy data-busy-label="Saving…">
        @csrf
        @method('put')

        <div>
            <x-input-label for="update_password_current_password" :value="__('Current Password')" />
            <x-text-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password" :value="__('New Password')" />
            <x-text-input id="update_password_password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            {{-- ITERATION-3: the transient "Saved." pill was removed — the
                 layout toast announces password-updated (humanized) once. --}}
        </div>
    </form>
    @else
        <p class="mt-6 text-sm text-gray-400">
            {{ __('You sign in with a linked account, so this account does not use a password yet.') }}
            {{ __('To add one, use the “Forgot password” link on the sign-in page with this account’s email address — you will set a password that way.') }}
        </p>
    @endif
</section>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6" data-busy data-busy-label="Saving…">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            {{-- ITERATION-7: set expectations for the email-change lifecycle —
                 a changed address starts unverified and a confirmation link is
                 sent to it (the old address also receives a security notice). --}}
            <p class="mt-2 text-xs text-gray-500">
                {{ __('Changing your email address requires re-verifying the new address.') }}
            </p>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-300">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="p-1 -m-1 underline text-sm text-brand-400 hover:text-brand-300 rounded-md hover:bg-white/[0.06] transition">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-emerald-400">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- ITERATION-7: identity confirmation for the email change — the same
             current-password bar the app already applies to account deletion
             and MFA disable. Server-side the rule is conditional (required
             only when the email is actually changing), so name-only edits
             stay one-step; the field is always visible so the form works
             identically with JavaScript disabled. --}}
        <div>
            <x-input-label for="confirm_email_password" :value="__('Current Password')" />
            <x-text-input
                id="confirm_email_password"
                name="password"
                type="password"
                class="mt-1 block w-full"
                autocomplete="current-password"
            />
            <p class="mt-2 text-xs text-gray-500">
                {{ __('Only required when changing your email address.') }}
            </p>
            <x-input-error class="mt-2" :messages="$errors->get('password')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            {{-- ITERATION-3: the transient "Saved." pill was removed — the
                 layout toast announces profile-updated (humanized) once. --}}
        </div>
    </form>
</section>
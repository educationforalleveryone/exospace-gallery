<x-app-layout>
    <x-slot name="header">
        <x-page-header title="MFA Verification" description="Enter the 6-digit code from your authenticator app to continue."/>
    </x-slot>

<div class="max-w-md mx-auto px-4 py-8">

    @if(session('info'))
        <div class="mb-4 rounded-lg bg-blue-500/10 border border-blue-500/30 px-4 py-3 text-sm text-blue-300">{{ session('info') }}</div>
    @endif

    <div class="card card-pad">
        {{-- ITERATION-6: one input, two credential kinds. The TOTP code is
             the primary path; users who lost their device can switch the
             form to a 10-character backup code. The input previously had
             maxlength="6" + pattern="\d{6}", which made a backup code
             IMPOSSIBLE to type — the recovery path existed in the
             controller but was unreachable through the UI. --}}
        <div x-data="{ backup: {{ $errors->has('code') && strlen(trim(old('code', ''))) > 6 ? 'true' : 'false' }} }">
            <form method="POST" action="{{ route('mfa.verify') }}" data-busy data-busy-label="Verifying…">
                @csrf
                <label for="code" class="label-text mb-1.5" x-text="backup ? 'Backup code:' : '6-digit code:'">6-digit code:</label>
                <input type="text" id="code" name="code" required maxlength="11" autofocus
                       :inputmode="backup ? 'text' : 'numeric'"
                       :autocomplete="backup ? 'off' : 'one-time-code'"
                       x-bind:placeholder="backup ? 'XXXXX-XXXXX' : '000000'"
                       value="{{ old('code') }}"
                       class="input-base h-12 text-center text-2xl tracking-widest uppercase {{ $errors->has('code') ? 'input-error' : '' }}" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                @error('code')
                    <p id="code-error" class="text-red-400 text-sm mt-2">{{ $message }}</p>
                @enderror

                <button type="submit" class="w-full mt-4 btn btn-primary">
                    Verify
                </button>
            </form>

            <div class="mt-4 text-center">
                <button type="button"
                        class="text-xs text-gray-400 hover:text-brand-300 underline underline-offset-2 transition"
                        @click="backup = ! backup"
                        x-text="backup ? 'Use an authenticator code instead' : 'Lost your device? Use a backup code instead'">Lost your device? Use a backup code instead</button>
                <p class="mt-2 text-xs text-gray-500" x-cloak x-show="backup">
                    Enter one of the 10 backup codes you saved when you enabled MFA (format XXXXX-XXXXX). Each works once.
                </p>
            </div>
        </div>
    </div>
</div>
</x-app-layout>

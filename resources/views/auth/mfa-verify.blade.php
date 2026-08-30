<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">MFA Verification</h2>
    </x-slot>

<div class="max-w-md mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Enter Your Authenticator Code</h1>
    <p class="text-sm text-gray-400 mb-6">Enter the 6-digit code from your authenticator app to access the super-admin panel.</p>

    @if(session('info'))
        <div class="mb-4 rounded-lg bg-blue-500/10 border border-blue-500/30 px-4 py-3 text-sm text-blue-300">{{ session('info') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
        <form method="POST" action="{{ route('mfa.verify') }}">
            @csrf
            <label for="code" class="block text-sm text-gray-300 mb-2">6-digit code:</label>
            <input type="text" id="code" name="code" required pattern="\d{6}" maxlength="6" autofocus
                   inputmode="numeric" autocomplete="one-time-code"
                   class="input-base h-12 text-center text-2xl tracking-widest {{ $errors->has('code') ? 'input-error' : '' }}"
                   placeholder="000000">
            @error('code')
                <p class="text-red-400 text-sm mt-2">{{ $message }}</p>
            @enderror

            <button type="submit" class="w-full mt-4 btn btn-primary w-full mt-4">
                Verify
            </button>
        </form>
    </div>
</div>
</x-app-layout>

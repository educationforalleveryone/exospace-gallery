<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-100 leading-tight">Set Up Multi-Factor Authentication</h2>
    </x-slot>

<div class="max-w-md mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Set Up Multi-Factor Authentication</h1>
    <p class="text-sm text-gray-400 mb-6">MFA is required for super-admin accounts. Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.).</p>

    @if(session('warning'))
        <div class="mb-4 rounded-lg bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-300">{{ session('warning') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
        <div class="text-center mb-6">
            <img src="{{ $qrCodeData }}" alt="MFA QR Code" class="mx-auto rounded-lg bg-white p-4" style="width: 200px; height: 200px;">
        </div>

        <div class="mb-6">
            <p class="text-xs text-gray-500 mb-2">Can't scan? Enter this code manually:</p>
            <code class="block bg-gray-800 text-green-400 text-sm font-mono p-3 rounded-lg break-all">{{ $secret }}</code>
        </div>

        <form method="POST" action="{{ route('mfa.setup') }}">
            @csrf
            <label for="code" class="block text-sm text-gray-300 mb-2">Enter the 6-digit code from your app:</label>
            <input type="text" id="code" name="code" required pattern="\d{6}" maxlength="6"
                   inputmode="numeric" autocomplete="one-time-code"
                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white text-center text-2xl tracking-widest focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none"
                   placeholder="000000">
            @error('code')
                <p class="text-red-400 text-sm mt-2">{{ $message }}</p>
            @enderror

            <button type="submit" class="w-full mt-4 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-3 rounded-xl transition">
                Verify & Enable MFA
            </button>
        </form>
    </div>
</div>
</x-app-layout>

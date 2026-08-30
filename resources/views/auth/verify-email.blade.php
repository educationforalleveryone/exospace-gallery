<x-guest-layout>
    <div class="text-center mb-6">
        <div class="w-14 h-14 bg-brand-600/20 border border-brand-500/30 rounded-xl flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-gray-100 mb-1">Check your inbox</h1>
        <p class="text-sm text-gray-400 leading-relaxed">
            We sent a verification link to your email. Click it to activate your account and get started.
        </p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 p-3 bg-emerald-900/40 border border-emerald-700/50 rounded-lg text-sm text-emerald-300 text-center">
            A fresh verification link has been sent.
        </div>
    @endif

    <div class="mb-6 bg-gray-900/50 border border-gray-700 rounded-xl p-4 space-y-3">
        <p class="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-2">Once verified, you can</p>
        <div class="flex items-center gap-3 text-sm text-gray-300">
            <div class="w-8 h-8 rounded-lg bg-brand-600/15 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <span>Build immersive 3D art exhibitions</span>
        </div>
        <div class="flex items-center gap-3 text-sm text-gray-300">
            <div class="w-8 h-8 rounded-lg bg-brand-600/15 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
            </div>
            <span>Share galleries with a single link</span>
        </div>
        <div class="flex items-center gap-3 text-sm text-gray-300">
            <div class="w-8 h-8 rounded-lg bg-blue-600/15 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <span>Track views with real-time analytics</span>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3">
        <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
            @csrf
            <x-primary-button class="w-full justify-center">
                Resend Email
            </x-primary-button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-ghost">
                Log out
            </button>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-gray-600">
        Didn't get an email? Check your spam folder, or resend above.
    </p>
</x-guest-layout>
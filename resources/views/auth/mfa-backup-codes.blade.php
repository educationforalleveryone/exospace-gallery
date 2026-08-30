<x-app-layout>
    <x-slot name="header">
        <x-page-header title="MFA Backup Codes" description="One-time codes that restore access if you lose your authenticator device." />
    </x-slot>

    {{-- ITERATION-9: this page was the last light-surface remnant in the
         product (a documented legacy defect since iteration 1) — white card
         on the dark canvas, raw max-w-2xl container with no mobile px-4,
         double py-12 padding, and a hand-rolled brand button. Now speaks
         the kit: .page-shell-narrow + .card .card-pad + .alert-warning +
         .well code chips + .btn.btn-primary. --}}
    <div class="page-shell-narrow">
        <div class="card card-pad">
            <div class="alert alert-warning mb-6">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <p class="font-semibold mb-0.5">Save these codes securely</p>
                    <p>
                        These one-time backup codes can be used to access your account if you lose your
                        authenticator device. Each code can only be used once.
                        <strong>You will not be able to see them again.</strong>
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-6">
                @foreach($codes as $code)
                    <div class="well font-mono text-lg text-gray-100 text-center select-all">{{ $code }}</div>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('super.index') }}"
                   class="btn btn-primary">
                    I've saved my codes — Continue →
                </a>
                <button data-click="windowPrint"
                        class="btn btn-secondary">
                    Print codes
                </button>
            </div>
        </div>
    </div>

    {{-- CSP-safe helper for the Print button (replaced inline onclick) --}}
    <script nonce="@nonce">
    window.windowPrint = function() { window.print(); };
    </script>
</x-app-layout>

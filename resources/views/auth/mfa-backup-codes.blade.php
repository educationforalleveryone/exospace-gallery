<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">
            MFA Backup Codes
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-sm text-yellow-800 font-semibold mb-1">⚠️ Save these codes securely</p>
                        <p class="text-sm text-yellow-700">
                            These one-time backup codes can be used to access your account if you lose your
                            authenticator device. Each code can only be used once.
                            <strong>You will not be able to see them again.</strong>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mb-6">
                        @foreach($codes as $code)
                            <div class="font-mono text-lg bg-gray-100 rounded-lg px-4 py-3 text-center select-all">
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>

                    <div class="flex gap-3">
                        <a href="{{ route('super.index') }}"
                           class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-semibold rounded-lg transition text-sm">
                            I've saved my codes — Continue →
                        </a>
                        <button data-click="windowPrint"
                                class="btn btn-secondary">
                            Print codes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- CSP-safe helper for the Print button (replaced inline onclick) --}}
    <script nonce="@nonce">
    window.windowPrint = function() { window.print(); };
    </script>
</x-app-layout>

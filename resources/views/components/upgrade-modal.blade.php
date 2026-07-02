@props(['trigger' => 'upgrade-modal'])

<div id="{{ $trigger }}"
     class="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 hidden items-center justify-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="{{ $trigger }}-heading">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl max-w-sm w-full shadow-2xl p-6 text-center relative">
        <button onclick="closeModal('{{ $trigger }}')"
                class="absolute top-3 right-3 text-gray-500 hover:text-gray-300 transition" aria-label="Close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <div class="w-12 h-12 bg-purple-500/10 rounded-full flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
        </div>

        <h3 id="{{ $trigger }}-heading" class="text-lg font-bold text-white mb-1">You've reached your gallery limit</h3>
        <p class="text-sm text-gray-400 mb-4">Pro gives you more galleries, more images, background music, exhibition scheduling, and no watermark.</p>

        <div class="bg-gray-800 rounded-xl p-3 mb-5 text-left space-y-2">
            {{-- (Task H04 / audit H6) — fixed copy. Previous version said
                 "Unlimited galleries, 50 images per gallery" which was
                 wrong on both counts: Pro is 5 galleries / 100 images
                 TOTAL (across all personal galleries, not per-gallery). --}}
            @foreach(['5 galleries · 100 images total', '7 venues including Industrial Loft & Dark Museum', 'Background music & exhibition scheduling', 'No Exospace watermark — $29 one-time'] as $feat)
            <div class="flex items-center gap-2 text-xs text-gray-300">
                <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                {{ $feat }}
            </div>
            @endforeach
        </div>

        <div class="space-y-2">
            <a href="{{ route('billing.upgrade', 'pro') }}" class="block w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold py-2.5 rounded-xl transition text-sm active:scale-95 text-center">
                Upgrade to Pro — $29
            </a>
            <button onclick="closeModal('{{ $trigger }}')"
                    class="block w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">
                Not now
            </button>
        </div>
    </div>
</div>

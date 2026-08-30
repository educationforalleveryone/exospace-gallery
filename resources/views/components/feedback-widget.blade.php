{{-- M-19: In-app feedback widget.
    Floating "Feedback" button (bottom-right) that opens a modal form.
    Included in layouts/app.blade.php so it appears on all admin pages.
    Uses Alpine.js for show/hide + fetch() for AJAX submission. --}}

@auth
<div x-data="{ open: false, submitting: false, success: false, category: 'bug', message: '' }"
     x-cloak
     x-effect="document.body.classList.toggle('overflow-y-hidden', open); if (open) $nextTick(() => $refs.panel && $refs.panel.focus())"
     class="fixed bottom-6 right-6 z-[45]">

    {{-- Floating button — z-[45] persistent-overlay tier: sits above chrome,
         under dropdowns/modals/toasts so it can never bury feedback. --}}
    <button @click="open = true; success = false; message = ''"
            x-show="!open"
            x-transition
            class="flex items-center gap-2 px-4 py-3 bg-brand-600 hover:bg-brand-500 text-white font-semibold rounded-full shadow-card-hover transition-all duration-200"
            aria-label="Send feedback"
            aria-haspopup="dialog">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <span class="text-sm">Feedback</span>
    </button>

    {{-- Modal --}}
    {{-- data-focus-trap: delegated Tab containment from app.js (this dialog
         has no Alpine Tab handler of its own). Panel takes focus on open so
         Escape/Tab work immediately without an extra click. --}}
    <div x-show="open"
         x-transition
         data-focus-trap
         class="fixed inset-0 z-[60] bg-black/60 backdrop-blur-sm flex items-end sm:items-center justify-center overflow-y-auto p-4"
         role="dialog"
         aria-modal="true"
         aria-labelledby="feedback-widget-title"
         @keydown.escape.window="open = false"
         @click="if($event.target === $el) open = false">

        <div x-ref="panel" tabindex="-1" class="bg-gray-800 border border-gray-600/50 rounded-xl shadow-modal max-w-md w-full p-6 focus:outline-none"
             @click.stop>

            {{-- Header --}}
            <div class="flex items-center justify-between mb-4">
                <h3 id="feedback-widget-title" class="modal-title">Send Feedback</h3>
                <button @click="open = false" class="modal-close -me-2" aria-label="Close">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Success state --}}
            <div x-show="success" x-transition class="text-center py-8">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-500/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <p class="text-white font-semibold text-sm mb-1">Thank you!</p>
                <p class="text-gray-400 text-sm">Your feedback has been sent to our team.</p>
                <button @click="open = false" class="btn btn-secondary mt-4">Close</button>
            </div>

            {{-- Form --}}
            <form x-show="!success"
                  @submit.prevent="submitting = true; fetch('{{ route('feedback.store') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'Accept': 'application/json' }, body: JSON.stringify({ category: category, message: message }) }).then(r => r.json()).then(data => { if(data.success) { success = true; } else { window.toast(data.error || 'Failed to submit feedback', 'error'); } }).catch(e => window.toast('Network error — please try again', 'error')).finally(() => submitting = false)"
                  class="space-y-4">

                {{-- Category --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(\App\Models\UserFeedback::CATEGORIES as $value => $label)
                        <button type="button"
                                @click="category = '{{ $value }}'"
                                :aria-pressed="category === '{{ $value }}' ? 'true' : 'false'"
                                :class="category === '{{ $value }}' ? 'bg-brand-600 border-brand-500 text-white' : 'bg-gray-700/50 border-gray-600 text-gray-400'"
                                class="px-3 py-2 rounded-lg border text-sm font-medium transition text-left">
                            {{ $label }}
                        </button>
                        @endforeach
                    </div>
                </div>

                {{-- Message --}}
                <div>
                    <label for="feedback-message" class="block text-sm font-medium text-gray-300 mb-2">Message</label>
                    <textarea x-model="message"
                              id="feedback-message"
                              rows="4"
                              required
                              maxlength="5000"
                              placeholder="Tell us what's on your mind..."
                              class="input-base resize-none"></textarea>
                    <p class="text-xs text-gray-500 mt-1" x-text="message.length + '/5000'"></p>
                </div>

                {{-- Submit --}}
                <button type="submit"
                        :disabled="submitting || !message.trim()"
                        class="btn btn-primary w-full">
                    <span class="btn-spinner" x-show="submitting" aria-hidden="true"></span>
                    <span x-show="!submitting">Send Feedback</span>
                    <span x-show="submitting">Sending…</span>
                </button>

                <p class="text-xs text-gray-500 text-center">
                    Your feedback is sent to the Exospace team. We read every message.
                </p>
            </form>
        </div>
    </div>
</div>
@endauth

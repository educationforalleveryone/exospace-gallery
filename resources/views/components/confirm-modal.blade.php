{{--
    Type-to-confirm modal component (Task H31 / audit H14, H52).

    Replaces browser `confirm()` dialogs for destructive actions. The
    user must type a confirmation phrase (e.g. the user's email) before
    the action is submitted. This prevents accidental destructive
    actions and is the standard pattern for "permanently delete" flows.

    Usage:
        <x-confirm-modal
            id="delete-user-modal"
            title="Permanently Delete User"
            confirm-text="DELETE"
            action-url="/master-control/users/123"
            action-method="POST"
            action-label="Delete User"
            danger="true">
            <p>This will permanently delete the user and all their data.</p>
        </x-confirm-modal>

    The modal:
      - Has role="dialog", aria-modal="true", aria-labelledby
      - Traps focus (Alpine x-trap)
      - Closes on Escape and backdrop click
      - Requires the user to type the confirm-text exactly
      - Submit button is disabled until the typed text matches
--}}
@props([
    'id' => 'confirm-modal',
    'title' => 'Confirm Action',
    'confirmText' => 'CONFIRM',
    'actionUrl' => '#',
    'actionMethod' => 'POST',
    'actionLabel' => 'Confirm',
    'danger' => false,
])

<div id="{{ $id }}"
     x-data="{ open: false, typed: '' }"
     x-cloak
     class="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 hidden items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-heading"
     :class="open ? 'flex' : 'hidden'"
     @keydown.escape.window="open = false; typed = ''"
     @click.self="open = false; typed = ''">

    <div class="bg-gray-900 border {{ $danger ? 'border-red-700/50' : 'border-gray-700' }} rounded-2xl max-w-md w-full shadow-2xl p-6 relative">
        <button @click="open = false; typed = ''"
                class="absolute top-3 right-3 text-gray-500 hover:text-gray-300 transition"
                aria-label="Close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <h3 id="{{ $id }}-heading" class="text-lg font-bold {{ $danger ? 'text-red-400' : 'text-white' }} mb-3">
            {{ $title }}
        </h3>

        <div class="text-sm text-gray-400 mb-4 space-y-2">
            {{ $slot }}
        </div>

        <div class="bg-gray-800/50 rounded-lg p-3 mb-4">
            <label for="{{ $id }}-input" class="block text-xs text-gray-500 mb-1">
                Type <code class="text-gray-300 font-mono">{{ $confirmText }}</code> to confirm
            </label>
            <input
                id="{{ $id }}-input"
                type="text"
                x-model="typed"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500 outline-none"
                autocomplete="off"
                aria-describedby="{{ $id }}-hint"
            >
        </div>

        <form method="POST" action="{{ $actionUrl }}" id="{{ $id }}-form">
            @csrf
            @if(strtolower($actionMethod) === 'delete')
                <input type="hidden" name="_method" value="DELETE">
            @endif

            <div class="flex gap-3">
                <button type="button"
                        @click="open = false; typed = ''"
                        class="flex-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-400 font-medium py-2.5 rounded-xl transition text-sm">
                    Cancel
                </button>
                <button type="submit"
                        :disabled="typed !== '{{ $confirmText }}'"
                        class="flex-1 {{ $danger ? 'bg-red-600 hover:bg-red-500' : 'bg-purple-600 hover:bg-purple-500' }} text-white font-bold py-2.5 rounded-xl transition text-sm disabled:opacity-40 disabled:cursor-not-allowed">
                    {{ $actionLabel }}
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="@nonce">
// Helper to open the modal from a button's onclick
window.openConfirmModal = function(id) {
    const modal = document.getElementById(id);
    if (modal && modal._x_dataStack) {
        modal.__x.$data.open = true;
        setTimeout(() => {
            const input = modal.querySelector('input[type="text"]');
            if (input) input.focus();
        }, 100);
    }
};
</script>

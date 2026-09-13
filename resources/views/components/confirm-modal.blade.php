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
     {{-- data-focus-trap: delegated Tab containment from app.js. --}}
     data-focus-trap
     class="modal-backdrop hidden items-center justify-center overflow-y-auto p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-heading"
     :class="open ? 'flex' : 'hidden'"
     @keydown.escape.window="open = false; typed = ''"
     @click.self="open = false; typed = ''">

    <div class="modal-panel max-w-md p-6 relative">
        <button @click="open = false; typed = ''"
                class="modal-close absolute top-3 right-3"
                aria-label="Close">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <h3 id="{{ $id }}-heading" class="section-title {{ $danger ? 'text-red-400' : 'text-white' }} mb-3">
            {{ $title }}
        </h3>

        <div class="text-sm text-gray-400 mb-4 space-y-2">
            {{ $slot }}
        </div>

        <div class="bg-gray-900/50 rounded-lg p-3 mb-4">
            <label for="{{ $id }}-input" class="hint-text block mb-1.5">
                Type <code class="text-gray-300 font-mono">{{ $confirmText }}</code> to confirm
            </label>
            <input
                id="{{ $id }}-input"
                type="text"
                x-model="typed"
                class="input-base"
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
                        class="btn btn-secondary flex-1">
                    Cancel
                </button>
                <button type="submit"
                        :disabled="typed !== '{{ $confirmText }}'"
                        class="btn {{ $danger ? 'btn-danger' : 'btn-primary' }} flex-1">
                    {{ $actionLabel }}
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="@nonce">
window.openConfirmModal = function(id) {
    const modal = document.getElementById(id);
    if (modal && modal._x_dataStack) {
        modal._x_dataStack[0].open = true;
        setTimeout(() => {
            const input = modal.querySelector('input[type="text"]');
            if (input) input.focus();
        }, 100);
    }
};
</script>

@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1.5 bg-gray-800'])

@php
/**
 * Dropdown menu component — the canonical dropdown/user-menu/context-menu.
 *
 * Accessibility (J-1 FIX, Iter-012 — preserved):
 *   - Trigger is a real <button> with aria-haspopup / :aria-expanded / aria-controls
 *   - Panel has role="menu" + aria-labelledby
 *   - ArrowDown/ArrowUp navigate items ([role="menuitem"] via x-dropdown-link)
 *   - Escape closes
 *
 * Visual language: the panel uses .menu-panel (bg-gray-800, border
 * gray-600/60, rounded-xl, shadow-menu). Trigger focus ring is brand —
 * previously indigo-500, which fought the purple accent system.
 *
 * CSP FIX (Iter-014, preserved): the x-data attribute uses &quot; entities
 * for the querySelectorAll('[role="menuitem"]') selector — do not revert to
 * raw quotes or Alpine receives invalid JS and every expression on the page
 * dies.
 */

$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};

// Stable id for ARIA wiring (aria-controls ↔ id, aria-labelledby ↔ id).
// Allows multiple dropdowns on the same page without id collisions.
$dropdownId = 'dd-' . uniqid();
@endphp

<div class="relative"
     x-data="{
         open: false,
         focusIndex: -1,
         items: [],
         init() {
             // Capture menu items for arrow-key navigation.
             // We use $nextTick so the panel DOM is available.
             this.$watch('open', (value) => {
                 if (value) {
                     this.$nextTick(() => {
                         this.items = Array.from(this.$refs.panel.querySelectorAll('[role=&quot;menuitem&quot;]'));
                     });
                 } else {
                     this.focusIndex = -1;
                 }
             });
         },
         focusNext() {
             if (this.items.length === 0) return;
             this.focusIndex = (this.focusIndex + 1) % this.items.length;
             this.items[this.focusIndex].focus();
         },
         focusPrev() {
             if (this.items.length === 0) return;
             this.focusIndex = this.focusIndex <= 0 ? this.items.length - 1 : this.focusIndex - 1;
             this.items[this.focusIndex].focus();
         },
     }"
     @click.outside="open = false"
     @close.stop="open = false"
     @keydown.escape.window="open = false">

    {{-- Real <button> trigger with ARIA state + keyboard support --}}
    <button type="button"
            id="{{ $dropdownId }}-trigger"
            aria-haspopup="true"
            :aria-expanded="open.toString()"
            :aria-controls="'{{ $dropdownId }}-panel'"
            @click="open = ! open"
            class="focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400/80 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-900 rounded-lg">
        {{ $trigger }}
    </button>

    {{-- Panel: role=menu + aria-labelledby + arrow-key navigation --}}
    <div x-show="open"
         x-ref="panel"
         id="{{ $dropdownId }}-panel"
         role="menu"
         aria-labelledby="{{ $dropdownId }}-trigger"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 mt-2 {{ $width }} {{ $alignmentClasses }}"
         style="display: none;"
         @click="open = false"
         @keydown.arrow-down.prevent="focusNext()"
         @keydown.arrow-up.prevent="focusPrev()"
         @keydown.escape.prevent="open = false; document.getElementById('{{ $dropdownId }}-trigger')?.focus()">
        <div class="menu-panel {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>

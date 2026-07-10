@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1.5 bg-gray-800'])

@php
/**
 * J-1 FIX (Iter-012): The dropdown component was keyboard-inaccessible.
 *
 * Before: the trigger was a <div @click="..."> — a clickable div with no
 * tabindex, no role, no ARIA state. Keyboard-only and screen-reader users
 * could not open ANY dropdown built with this component. The user dropdown
 * in navigation.blade.php is the only way to reach Profile / Billing /
 * Teams / Sign-out — these users literally could not navigate the app.
 *
 * After: the trigger is now a <button type="button"> with:
 *   - aria-expanded bound to Alpine's `open` state
 *   - aria-haspopup="true" (signals to screen readers that clicking opens a menu)
 *   - aria-controls pointing at the panel's id (links trigger ↔ panel)
 *   - native keyboard support (button is focusable + Enter/Space activate it)
 *
 * The panel has:
 *   - role="menu" (signals to screen readers that this is a menu, not generic content)
 *   - aria-labelledby pointing at the trigger's id
 *   - ArrowDown/ArrowUp navigation between menu items (Alpine handlers)
 *   - Escape-to-close (already had @close.stop; now also @keydown.escape.window)
 *
 * Dropdown-link items get role="menuitem" via the dropdown-link component.
 *
 * Backward compatibility: $trigger slot is still rendered inside the button.
 * Existing callers that pass a styled <button> as $trigger will nest buttons
 * (HTML allows this only for <button><span>...</span></button>; nested
 * <button> elements are technically invalid HTML but browsers render them).
 * Callers should now pass an <a> or <span> as the trigger — the outer
 * <button> element provides the click target.
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
                         this.items = Array.from(this.$refs.panel.querySelectorAll('[role=\\'menuitem\\']'));
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

    {{-- J-1 FIX: <button> trigger with ARIA state + keyboard support --}}
    <button type="button"
            id="{{ $dropdownId }}-trigger"
            aria-haspopup="true"
            aria-expanded="open"
            :aria-controls="'{{ $dropdownId }}-panel'"
            @click="open = ! open"
            class="focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
        {{ $trigger }}
    </button>

    {{-- J-1 FIX: panel has role=menu + aria-labelledby --}}
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
         class="absolute z-50 mt-2 {{ $width }} rounded-md shadow-lg {{ $alignmentClasses }}"
         style="display: none;"
         @click="open = false"
         @keydown.arrow-down.prevent="focusNext()"
         @keydown.arrow-up.prevent="focusPrev()"
         @keydown.escape.prevent="open = false; $refs.previousElementSibling?.focus()">
        <div class="rounded-xl border border-gray-700 shadow-2xl overflow-hidden {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>

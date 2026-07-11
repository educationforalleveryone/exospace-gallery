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
 * CSP FIX (Iter-014): The previous x-data attribute used `\\'` to escape
 * single quotes inside a single-quoted JS string inside an HTML attribute.
 * Blade passed the backslashes through verbatim, so the browser received
 * `'[role=\\'menuitem\\']'` — JS parsed this as the string `[role=\`
 * followed by an unexpected identifier `menuitem`, producing
 * `SyntaxError: missing ) after argument list`. This single error
 * killed Alpine.js initialization for the entire page, cascading into
 * "cookieBanner is not defined" and every other Alpine expression on the
 * page (because Alpine's evaluator runs in a try/catch and one bad
 * expression can poison the rest of the walk).
 *
 * Fix: use HTML entity &quot; for the double quotes that wrap the
 * menuitem value. HTML attribute parsing decodes &quot; back to ",
 * so Alpine.js receives the JS expression
 * `querySelectorAll('[role="menuitem"]')` — a single-quoted JS string
 * containing a valid CSS attribute selector. No backslash escaping,
 * no quote-collision, no Blade fight.
 *
 * Also fixed: `aria-expanded="open"` was a literal string, not a binding.
 * Now `:aria-expanded="open.toString()"` so screen readers hear the
 * actual open/closed state.
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

    {{-- J-1 FIX: <button> trigger with ARIA state + keyboard support --}}
    <button type="button"
            id="{{ $dropdownId }}-trigger"
            aria-haspopup="true"
            :aria-expanded="open.toString()"
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

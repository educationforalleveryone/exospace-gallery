@php
/**
 * J-1 FIX (Iter-012): Added role="menuitem" + tabindex="-1" so the
 * parent <x-dropdown>'s arrow-key navigation can focus these items.
 *
 * tabindex="-1" means the link is NOT in the tab order directly (the
 * parent <button> trigger handles Tab focus). The link is focusable
 * via element.focus() (called by the dropdown's focusNext/focusPrev),
 * which lets ArrowDown/ArrowUp navigate between items.
 *
 * The class list is unchanged from the previous version — visual
 * styling is preserved.
 */
@endphp
<a {{ $attributes->merge(['role' => 'menuitem', 'tabindex' => '-1', 'class' => 'flex items-center w-full px-4 py-2.5 text-start text-sm leading-5 text-gray-300 hover:bg-white/[0.04] hover:text-white focus:outline-none focus:bg-white/[0.04] focus:text-white transition duration-150 ease-in-out']) }}>{{ $slot }}</a>

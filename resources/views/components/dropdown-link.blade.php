@php
/**
 * Dropdown menu item — pairs with <x-dropdown>.
 *
 - role="menuitem" + tabindex="-1" (J-1 FIX, Iter-012): the parent
 * dropdown's arrow-key navigation focuses these items via element.focus();
 * they are intentionally out of the direct tab order.
 *
 * Visual: uses the shared .menu-item recipe (was an ad-hoc class string —
 * now identical to every other menu row in the product).
 */
@endphp
<a {{ $attributes->merge(['role' => 'menuitem', 'tabindex' => '-1', 'class' => 'menu-item']) }}>{{ $slot }}</a>

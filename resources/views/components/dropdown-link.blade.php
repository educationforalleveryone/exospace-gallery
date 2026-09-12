@php
@endphp
<a {{ $attributes->merge(['role' => 'menuitem', 'tabindex' => '-1', 'class' => 'menu-item']) }}>{{ $slot }}</a>
